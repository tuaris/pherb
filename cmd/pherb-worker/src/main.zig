const std = @import("std");
const nats = @import("nats");
const posix = std.posix;
const c = std.c;
const zio = @import("zio");
const sdk = @import("opentelemetry-sdk");

pub const std_options: std.Options = .{
    .logFn = sdk.logs.std_log_bridge.logFn,
};

const log = std.log.scoped(.pherb_worker);

pub const version = "0.8.0";

const subject_completed = "pherb.pipeline.completed";

const max_argv = 64;
const max_base_argv = 8;
const max_stderr_bytes = 4096;
const default_ack_wait_secs: u32 = 300;
const default_stream = "PHERB";

const Config = struct {
    nats_url: []const u8 = "nats://127.0.0.1:4222",
    stream: []const u8 = default_stream,
    subject: []const u8 = "",
    consumer: []const u8 = "",
    ack_wait: u32 = default_ack_wait_secs,
    base_argv: [max_base_argv][]const u8 = undefined,
    base_argc: usize = 0,

    fn loadFromFile(allocator: std.mem.Allocator, path: []const u8) !Config {
        var config = Config{};
        const content = std.fs.cwd().readFileAlloc(allocator, path, 64 * 1024) catch |err| {
            if (err == error.FileNotFound) {
                log.err("config file not found: {s}", .{path});
                std.process.exit(1);
            }
            return err;
        };
        defer allocator.free(content);

        var in_stage = false;
        var iter = std.mem.splitScalar(u8, content, '\n');
        while (iter.next()) |raw_line| {
            const line = std.mem.trim(u8, raw_line, &std.ascii.whitespace);
            if (line.len == 0 or line[0] == '#' or line[0] == ';') continue;

            // Section header
            if (line[0] == '[') {
                in_stage = std.mem.eql(u8, line, "[stage]");
                continue;
            }

            const eq = std.mem.indexOfScalar(u8, line, '=') orelse continue;
            const key = std.mem.trim(u8, line[0..eq], &std.ascii.whitespace);
            const val = std.mem.trim(u8, line[eq + 1 ..], &std.ascii.whitespace);
            if (val.len == 0) continue;

            if (in_stage) {
                if (std.mem.eql(u8, key, "subject")) {
                    config.subject = try allocator.dupe(u8, val);
                } else if (std.mem.eql(u8, key, "consumer")) {
                    config.consumer = try allocator.dupe(u8, val);
                } else if (std.mem.eql(u8, key, "ack_wait")) {
                    config.ack_wait = std.fmt.parseInt(u32, val, 10) catch default_ack_wait_secs;
                } else if (std.mem.eql(u8, key, "command")) {
                    // Split command value into base argv tokens
                    var tok_iter = std.mem.splitScalar(u8, val, ' ');
                    while (tok_iter.next()) |tok| {
                        if (tok.len == 0) continue;
                        if (config.base_argc >= max_base_argv) break;
                        config.base_argv[config.base_argc] = try allocator.dupe(u8, tok);
                        config.base_argc += 1;
                    }
                }
            } else {
                if (std.mem.eql(u8, key, "nats_url")) {
                    config.nats_url = try allocator.dupe(u8, val);
                } else if (std.mem.eql(u8, key, "stream")) {
                    config.stream = try allocator.dupe(u8, val);
                }
            }
        }

        if (config.subject.len == 0) {
            log.err("missing 'subject' in [stage] section", .{});
            std.process.exit(1);
        }
        if (config.consumer.len == 0) {
            log.err("missing 'consumer' in [stage] section", .{});
            std.process.exit(1);
        }
        if (config.base_argc == 0) {
            log.err("missing 'command' in [stage] section", .{});
            std.process.exit(1);
        }

        log.info("loaded config from {s}", .{path});
        return config;
    }
};

/// Active job — tracks a running child process.
const ActiveJob = struct {
    child_pid: posix.pid_t,
    job_id: []const u8,
    stage_name: []const u8,
    output_path: []const u8,
    post_rename: ?[]const u8 = null,
    stderr_fd: ?posix.fd_t = null,
};

pub fn main() !void {
    var gpa = std.heap.GeneralPurposeAllocator(.{}){};
    defer _ = gpa.deinit();
    const allocator = gpa.allocator();

    // Parse -c flag for config file path
    const args = try std.process.argsAlloc(allocator);
    defer std.process.argsFree(allocator, args);

    var config_path: []const u8 = "/usr/local/etc/pherb/worker.conf";
    var i: usize = 1;
    while (i < args.len) : (i += 1) {
        if (std.mem.eql(u8, args[i], "-c") and i + 1 < args.len) {
            i += 1;
            config_path = args[i];
        } else if (std.mem.eql(u8, args[i], "-v") or std.mem.eql(u8, args[i], "--version")) {
            var ver_buf: [64]u8 = undefined;
            const ver_msg = std.fmt.bufPrint(&ver_buf, "pherb-worker {s}\n", .{version}) catch unreachable;
            std.fs.File.stdout().writeAll(ver_msg) catch {};
            return;
        }
    }

    const config = try Config.loadFromFile(allocator, config_path);

    log.info("pherb-worker {s} starting", .{version});
    log.info("NATS: {s}", .{config.nats_url});
    log.info("stream: {s}", .{config.stream});
    log.info("stage: {s} → {s} (consumer={s}, ack_wait={d}s)", .{
        config.subject, config.base_argv[0], config.consumer, config.ack_wait,
    });

    // Initialize ZIO runtime (required by nats library — uses kqueue on FreeBSD)
    const rt = try zio.Runtime.init(allocator, .{});
    defer rt.deinit();

    // Create kqueue for event-driven child process monitoring
    const kq = try posix.kqueue();
    defer posix.close(kq);

    // Connect to NATS (retry with backoff for boot ordering)
    var conn = nats.Connection.init(allocator, .{});
    defer conn.deinit();

    {
        var attempts: u32 = 0;
        while (true) {
            conn.connect(config.nats_url) catch |err| {
                attempts += 1;
                if (attempts >= 24) {
                    log.err("NATS connect failed after {} attempts, exiting", .{attempts});
                    std.process.exit(1);
                }
                log.info("connect attempt {}/24 failed: {}, retrying in 5s...", .{ attempts, err });
                std.Thread.sleep(5 * std.time.ns_per_s);
                conn.deinit();
                conn = nats.Connection.init(allocator, .{});
                continue;
            };
            break;
        }
    }

    log.info("connected to NATS", .{});

    // Create JetStream context
    const js = nats.JetStream.init(&conn, .{});

    // Create pull subscription for this stage's durable consumer
    const pull_sub = js.pullSubscribe(
        config.subject,
        config.consumer,
        .{
            .stream = config.stream,
            .config = .{
                .ack_wait = @as(u64, config.ack_wait) * std.time.ns_per_s,
                .filter_subject = config.subject,
            },
        },
    ) catch |err| {
        log.err("pullSubscribe failed: {}", .{err});
        std.process.exit(1);
    };
    defer pull_sub.deinit();

    log.info("pull consumer ready, waiting for jobs...", .{});

    // Heartbeat interval: send inProgress every ack_wait/3 seconds
    const heartbeat_interval_ns: u64 = (@as(u64, config.ack_wait) / 3) * std.time.ns_per_s;

    // Main loop — pull one job at a time, process it, ack, repeat.
    while (true) {
        // Pull a single message (blocking with 30s timeout)
        var batch = pull_sub.fetch(1, 30000) catch |err| {
            if (err == error.Timeout) continue;
            log.err("fetch error: {}", .{err});
            std.Thread.sleep(1 * std.time.ns_per_s);
            continue;
        };
        defer batch.deinit();

        if (batch.messages.len == 0) continue;

        var js_msg = batch.messages[0];

        // Parse payload from the JetStream message
        const data = js_msg.msg.data;
        if (data.len == 0) {
            log.warn("empty message, acking and skipping", .{});
            js_msg.ack() catch {};
            continue;
        }

        const parsed = std.json.parseFromSlice(std.json.Value, allocator, data, .{}) catch {
            log.err("invalid JSON payload, acking and skipping", .{});
            js_msg.ack() catch {};
            continue;
        };
        defer parsed.deinit();

        const root = parsed.value.object;
        const job_id_raw = getStr(root, "job_id") orelse "unknown";
        const output_path_raw = getStr(root, "output_path") orelse {
            log.err("[{s}] missing output_path in payload", .{job_id_raw});
            js_msg.ack() catch {};
            continue;
        };
        const post_rename_raw = getStr(root, "post_rename");

        // Derive stage name from subject (last segment after '.')
        const stage_name: []const u8 = blk: {
            if (std.mem.lastIndexOfScalar(u8, config.subject, '.')) |idx| {
                break :blk config.subject[idx + 1 ..];
            }
            break :blk config.subject;
        };

        log.info("[{s}] {s}: → {s}", .{ job_id_raw, stage_name, output_path_raw });

        // Build argv: base_argv from config + args array from payload
        var argv_buf: [max_argv][]const u8 = undefined;
        var argc: usize = 0;

        for (config.base_argv[0..config.base_argc]) |base_arg| {
            if (argc >= max_argv) break;
            argv_buf[argc] = base_arg;
            argc += 1;
        }

        if (root.get("args")) |args_val| {
            switch (args_val) {
                .array => |arr| {
                    for (arr.items) |item| {
                        if (argc >= max_argv) break;
                        switch (item) {
                            .string => |s| {
                                argv_buf[argc] = s;
                                argc += 1;
                            },
                            else => {},
                        }
                    }
                },
                else => {},
            }
        }

        if (argc == 0) {
            log.err("[{s}] empty command (no base argv)", .{job_id_raw});
            publishEvent(&js, job_id_raw, stage_name, "failed", null, "empty command");
            js_msg.ack() catch {};
            continue;
        }

        const argv = argv_buf[0..argc];

        // Spawn child process
        var child = std.process.Child.init(argv, allocator);
        child.stderr_behavior = .Pipe;
        child.stdout_behavior = .Ignore;

        child.spawn() catch |err| {
            log.err("[{s}] spawn failed: {}", .{ job_id_raw, err });
            publishEvent(&js, job_id_raw, stage_name, "failed", null, "spawn failed");
            js_msg.ack() catch {};
            continue;
        };

        const child_pid: posix.pid_t = @intCast(child.id);
        log.info("[{s}] spawned pid {d}", .{ job_id_raw, child_pid });

        // Register for kqueue exit notification
        const change = posix.Kevent{
            .ident = @intCast(child_pid),
            .filter = c.EVFILT.PROC,
            .flags = c.EV.ADD | c.EV.ONESHOT,
            .fflags = c.NOTE.EXIT,
            .data = 0,
            .udata = 0,
        };
        _ = posix.kevent(kq, &.{change}, &.{}, null) catch |err| {
            log.err("[{s}] kevent register failed: {}", .{ job_id_raw, err });
        };

        // Wait for child exit with periodic inProgress heartbeats
        var child_exited = false;
        var last_heartbeat = std.time.nanoTimestamp();

        while (!child_exited) {
            // Non-blocking kqueue check for child exit
            var events: [1]posix.Kevent = undefined;
            const zero_timeout = posix.timespec{ .sec = 0, .nsec = 0 };
            const n = posix.kevent(kq, &.{}, &events, &zero_timeout) catch 0;
            if (n > 0) {
                child_exited = true;
                break;
            }

            // Send inProgress heartbeat if interval elapsed
            const now = std.time.nanoTimestamp();
            const elapsed: u64 = @intCast(now - last_heartbeat);
            if (elapsed >= heartbeat_interval_ns) {
                js_msg.inProgress() catch |err| {
                    log.warn("[{s}] inProgress heartbeat failed: {}", .{ job_id_raw, err });
                };
                last_heartbeat = now;
            }

            // Sleep briefly to avoid busy-spinning
            std.Thread.sleep(100 * std.time.ns_per_ms);
        }

        // Child exited — read exit code and stderr
        var kq_events: [1]posix.Kevent = undefined;
        const zero_ts = posix.timespec{ .sec = 0, .nsec = 0 };
        _ = posix.kevent(kq, &.{}, &kq_events, &zero_ts) catch 0;

        // Get exit status via waitpid
        const wait_result = posix.waitpid(child_pid, 0);
        const exit_code: u32 = if (wait_result.status & 0x7f == 0) (wait_result.status >> 8) & 0xff else 255;

        // Read stderr
        var stderr_output: []const u8 = "";
        var stderr_alloc: ?[]u8 = null;
        if (child.stderr) |stderr_stream| {
            var stderr_buf: [max_stderr_bytes]u8 = undefined;
            var total: usize = 0;
            while (total < max_stderr_bytes) {
                const n_read = posix.read(stderr_stream.handle, stderr_buf[total..]) catch break;
                if (n_read == 0) break;
                total += n_read;
            }
            posix.close(stderr_stream.handle);
            if (total > 0) {
                stderr_alloc = allocator.dupe(u8, stderr_buf[0..total]) catch null;
                if (stderr_alloc) |s| stderr_output = s;
            }
        }
        defer if (stderr_alloc) |s| allocator.free(s);

        if (exit_code != 0) {
            log.err("[{s}] {s} exited with code {d}", .{ job_id_raw, stage_name, exit_code });
            if (stderr_output.len > 0) {
                log.err("[{s}] stderr: {s}", .{ job_id_raw, stderr_output });
            }
            var err_buf: [512]u8 = undefined;
            const err_detail = if (stderr_output.len > 0)
                std.fmt.bufPrint(&err_buf, "{s} exit {d}: {s}", .{ stage_name, exit_code, stderr_output[0..@min(stderr_output.len, 384)] }) catch "process failed"
            else
                std.fmt.bufPrint(&err_buf, "{s} exit {d}", .{ stage_name, exit_code }) catch "process failed";
            publishEvent(&js, job_id_raw, stage_name, "failed", null, err_detail);
        } else {
            // Post-rename: move produced file to output_path if specified
            if (post_rename_raw) |rename_from| {
                const from_z = allocator.dupeZ(u8, rename_from) catch null;
                const to_z = allocator.dupeZ(u8, output_path_raw) catch null;
                if (from_z != null and to_z != null) {
                    const result = std.c.rename(from_z.?, to_z.?);
                    if (result != 0) {
                        log.err("[{s}] post_rename failed: {s} → {s}", .{ job_id_raw, rename_from, output_path_raw });
                    }
                    allocator.free(from_z.?);
                    allocator.free(to_z.?);
                }
            }

            log.info("[{s}] {s} done, output at {s}", .{ job_id_raw, stage_name, output_path_raw });
            publishEvent(&js, job_id_raw, stage_name, "completed", output_path_raw, null);
        }

        // ACK the stage message — job processed (success or failure)
        js_msg.ack() catch |err| {
            log.err("[{s}] ack failed: {}", .{ job_id_raw, err });
        };
    }
}

/// Publish a pipeline event (completion or failure) via JetStream.
fn publishEvent(
    js: *const nats.JetStream,
    job_id: []const u8,
    stage_name: []const u8,
    status: []const u8,
    output_path: ?[]const u8,
    err_reason: ?[]const u8,
) void {
    var buf: [1024]u8 = undefined;
    const evt = if (output_path) |op|
        std.fmt.bufPrint(&buf,
            "{{\"job_id\":\"{s}\",\"stage\":\"{s}\",\"status\":\"{s}\",\"output_path\":\"{s}\"}}",
            .{ job_id, stage_name, status, op },
        ) catch return
    else if (err_reason) |reason|
        std.fmt.bufPrint(&buf,
            "{{\"job_id\":\"{s}\",\"stage\":\"{s}\",\"status\":\"{s}\",\"error\":\"{s}\"}}",
            .{ job_id, stage_name, status, reason },
        ) catch return
    else
        std.fmt.bufPrint(&buf,
            "{{\"job_id\":\"{s}\",\"stage\":\"{s}\",\"status\":\"{s}\"}}",
            .{ job_id, stage_name, status },
        ) catch return;

    const pub_result = js.publish(subject_completed, evt, .{});
    if (pub_result) |ack| {
        ack.deinit();
    } else |err| {
        log.err("[{s}] failed to publish {s} event: {}", .{ job_id, status, err });
    }
}

fn getStr(obj: std.json.ObjectMap, key: []const u8) ?[]const u8 {
    const val = obj.get(key) orelse return null;
    return switch (val) {
        .string => |s| s,
        else => null,
    };
}
