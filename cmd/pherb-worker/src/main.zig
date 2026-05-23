const std = @import("std");
const nats = @import("nats");
const posix = std.posix;
const c = std.c;
const zio = @import("zio");

const log = std.log.scoped(.pherb_worker);

pub const version = "0.7.0";

const subject_completed = "pherb.pipeline.completed";

const max_stages = 16;
const max_argv = 64;
const max_base_argv = 8;

const StageEntry = struct {
    subject: []const u8,
    base_argv: [max_base_argv][]const u8 = undefined,
    base_argc: usize = 0,
};

const Config = struct {
    nats_url: []const u8 = "nats://127.0.0.1:4222",
    stages: [max_stages]?StageEntry = .{null} ** max_stages,
    stage_count: usize = 0,

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

        var in_stages = false;
        var iter = std.mem.splitScalar(u8, content, '\n');
        while (iter.next()) |raw_line| {
            const line = std.mem.trim(u8, raw_line, &std.ascii.whitespace);
            if (line.len == 0 or line[0] == '#' or line[0] == ';') continue;

            // Section header
            if (line[0] == '[') {
                in_stages = std.mem.eql(u8, line, "[stages]");
                continue;
            }

            const eq = std.mem.indexOfScalar(u8, line, '=') orelse continue;
            const key = std.mem.trim(u8, line[0..eq], &std.ascii.whitespace);
            const val = std.mem.trim(u8, line[eq + 1 ..], &std.ascii.whitespace);
            if (val.len == 0) continue;

            if (in_stages) {
                if (config.stage_count >= max_stages) {
                    log.warn("max stages ({d}) reached, ignoring: {s}", .{ max_stages, key });
                    continue;
                }
                var entry = StageEntry{
                    .subject = try allocator.dupe(u8, key),
                };
                // Split config value into base argv tokens (binary + fixed args)
                var tok_iter = std.mem.splitScalar(u8, val, ' ');
                while (tok_iter.next()) |tok| {
                    if (tok.len == 0) continue;
                    if (entry.base_argc >= max_base_argv) break;
                    entry.base_argv[entry.base_argc] = try allocator.dupe(u8, tok);
                    entry.base_argc += 1;
                }
                if (entry.base_argc > 0) {
                    config.stages[config.stage_count] = entry;
                    config.stage_count += 1;
                }
            } else {
                if (std.mem.eql(u8, key, "nats_url")) {
                    config.nats_url = try allocator.dupe(u8, val);
                }
            }
        }

        if (config.stage_count == 0) {
            log.err("no [stages] defined in config", .{});
            std.process.exit(1);
        }

        log.info("loaded config from {s}", .{path});
        return config;
    }

    fn findStage(self: *const Config, subject: []const u8) ?StageEntry {
        for (self.stages[0..self.stage_count]) |entry| {
            if (entry) |e| {
                if (std.mem.eql(u8, e.subject, subject)) return e;
            }
        }
        return null;
    }
};

/// Active job — tracks a running child process.
const ActiveJob = struct {
    child_pid: posix.pid_t,
    job_id: []const u8,
    stage_name: []const u8,
    output_path: []const u8,
    post_rename: ?[]const u8 = null,
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
    log.info("stages: {d} configured", .{config.stage_count});
    for (config.stages[0..config.stage_count]) |entry| {
        if (entry) |e| {
            if (e.base_argc > 0) {
                log.info("  {s} → {s}", .{ e.subject, e.base_argv[0] });
            }
        }
    }

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
        var backoff: u64 = 1;
        while (true) {
            conn.connect(config.nats_url) catch |err| {
                log.info("Connection failed: {}", .{err});
                if (backoff >= 30) {
                    log.err("NATS connect failed after retries, exiting", .{});
                    std.process.exit(1);
                }
                log.info("retrying in {}s...", .{backoff});
                std.Thread.sleep(backoff * std.time.ns_per_s);
                backoff = @min(backoff * 2, 30);
                continue;
            };
            break;
        }
    }

    log.info("connected to NATS", .{});

    // Subscribe to wildcard — single subscription handles all subjects.
    // Command lookup filters to only configured stages.
    const sub = conn.subscribeSync("pherb.worker.>") catch |err| {
        log.err("subscribe failed: {}", .{err});
        std.process.exit(1);
    };
    defer sub.deinit();

    log.info("subscribed to pherb.worker.>, waiting for jobs...", .{});

    // Main loop — event-driven via kqueue for child exit, NATS for messages.
    var active_job: ?ActiveJob = null;

    while (true) {
        // Check kqueue for child exit event (non-blocking)
        if (active_job) |*job| {
            if (checkChildExit(kq, allocator, &conn, job)) {
                active_job = null;
            }
        }

        // Wait for NATS messages — short timeout if child is running, long if idle
        const timeout: u64 = if (active_job != null) 100 else 30000;
        var msg = sub.nextMsg(timeout) catch |err| {
            if (err == error.Timeout) continue;
            log.err("receive error: {}", .{err});
            continue;
        };
        defer msg.deinit();

        // Ignore messages while busy — worker processes one job at a time.
        // The dispatching consumer holds the JetStream message and sends
        // InProgress heartbeats; no busy reply needed.
        if (active_job != null) {
            continue;
        }

        // Look up stage for this subject — ignore unconfigured subjects
        const stage = config.findStage(msg.subject) orelse {
            continue;
        };

        active_job = spawnJob(allocator, &conn, kq, msg, &stage) catch |err| {
            log.err("spawn failed: {}", .{err});
            continue;
        };
    }
}

/// Check kqueue for child exit event. Returns true if child exited.
fn checkChildExit(kq: i32, allocator: std.mem.Allocator, conn: *nats.Connection, job: *ActiveJob) bool {
    var events: [1]posix.Kevent = undefined;
    const zero_timeout = posix.timespec{ .sec = 0, .nsec = 0 };
    const n = posix.kevent(kq, &.{}, &events, &zero_timeout) catch |err| {
        log.err("[{s}] kevent check failed: {}", .{ job.job_id, err });
        return false;
    };
    if (n == 0) return false;

    // EVFILT_PROC event fired — child exited
    const raw: u32 = @truncate(@as(u64, @bitCast(events[0].data)));
    const exit_code: u32 = if (raw & 0x7f == 0) (raw >> 8) & 0xff else 255;

    // Reap the zombie
    _ = posix.waitpid(job.child_pid, 1);

    if (exit_code != 0) {
        log.err("[{s}] {s} exited with code {d}", .{ job.job_id, job.stage_name, exit_code });
        var err_buf: [128]u8 = undefined;
        const err_detail = std.fmt.bufPrint(&err_buf, "{s} exit {d}", .{ job.stage_name, exit_code }) catch "process failed";
        publishFailed(conn, job.job_id, job.stage_name, err_detail);
    } else {
        // Post-rename: move produced file to output_path if specified
        if (job.post_rename) |rename_from| {
            const from_z = allocator.dupeZ(u8, rename_from) catch null;
            const to_z = allocator.dupeZ(u8, job.output_path) catch null;
            if (from_z != null and to_z != null) {
                const result = std.c.rename(from_z.?, to_z.?);
                if (result != 0) {
                    log.err("[{s}] post_rename failed: {s} → {s}", .{ job.job_id, rename_from, job.output_path });
                }
                allocator.free(from_z.?);
                allocator.free(to_z.?);
            }
        }

        log.info("[{s}] {s} done, output at {s}", .{ job.job_id, job.stage_name, job.output_path });

        var evt_buf: [768]u8 = undefined;
        const evt = std.fmt.bufPrint(&evt_buf,
            "{{\"job_id\":\"{s}\",\"stage\":\"{s}\",\"status\":\"completed\",\"output_path\":\"{s}\"}}",
            .{ job.job_id, job.stage_name, job.output_path },
        ) catch unreachable;
        conn.publish(subject_completed, evt) catch |err| {
            log.err("[{s}] failed to publish completion: {}", .{ job.job_id, err });
        };
    }

    // Free allocated resources
    allocator.free(job.job_id);
    allocator.free(job.stage_name);
    allocator.free(job.output_path);
    if (job.post_rename) |pr| allocator.free(pr);
    return true;
}

/// Spawn a job: config provides base argv (binary), payload provides args array.
fn spawnJob(
    allocator: std.mem.Allocator,
    conn: *nats.Connection,
    kq: i32,
    msg: *nats.Message,
    stage: *const StageEntry,
) !?ActiveJob {
    const data = msg.data;
    if (data.len == 0) {
        log.warn("empty message, ignoring", .{});
        return null;
    }

    // Parse JSON payload
    const parsed = std.json.parseFromSlice(std.json.Value, allocator, data, .{}) catch {
        log.err("invalid JSON payload", .{});
        return null;
    };
    defer parsed.deinit();

    const root = parsed.value.object;
    const job_id_raw = getStr(root, "job_id") orelse "unknown";
    const output_path_raw = getStr(root, "output_path") orelse {
        log.err("[{s}] missing output_path in payload", .{job_id_raw});
        return null;
    };
    const post_rename_raw = getStr(root, "post_rename");

    // Derive stage name from subject (last segment after '.')
    const stage_name_raw = blk: {
        if (std.mem.lastIndexOfScalar(u8, msg.subject, '.')) |idx| {
            break :blk msg.subject[idx + 1 ..];
        }
        break :blk msg.subject;
    };

    // Dupe strings — they must outlive the message
    const job_id = try allocator.dupe(u8, job_id_raw);
    errdefer allocator.free(job_id);
    const output_path = try allocator.dupe(u8, output_path_raw);
    errdefer allocator.free(output_path);
    const stage_name = try allocator.dupe(u8, stage_name_raw);
    errdefer allocator.free(stage_name);
    const post_rename: ?[]const u8 = if (post_rename_raw) |pr| try allocator.dupe(u8, pr) else null;
    errdefer if (post_rename) |pr| allocator.free(pr);

    log.info("[{s}] {s}: → {s}", .{ job_id, stage_name, output_path });

    // Build argv: base_argv from config + args array from payload
    var argv_buf: [max_argv][]const u8 = undefined;
    var argc: usize = 0;

    // Copy base argv from config (not allocated — config owns these)
    for (stage.base_argv[0..stage.base_argc]) |base_arg| {
        if (argc >= max_argv) break;
        argv_buf[argc] = base_arg;
        argc += 1;
    }

    // Append args from payload JSON array
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
        log.err("[{s}] empty command (no base argv)", .{job_id});
        publishFailed(conn, job_id, stage_name, "empty command");
        return null;
    }

    const argv = argv_buf[0..argc];

    // Spawn child process
    var child = std.process.Child.init(argv, allocator);
    child.stderr_behavior = .Ignore;
    child.stdout_behavior = .Ignore;

    child.spawn() catch |err| {
        log.err("[{s}] spawn failed: {}", .{ job_id, err });
        publishFailed(conn, job_id, stage_name, "spawn failed");
        return null;
    };

    const child_pid: posix.pid_t = @intCast(child.id);
    log.info("[{s}] spawned pid {d}", .{ job_id, child_pid });

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
        log.err("[{s}] kevent register failed: {}", .{ job_id, err });
    };

    return ActiveJob{
        .child_pid = child_pid,
        .job_id = job_id,
        .stage_name = stage_name,
        .output_path = output_path,
        .post_rename = post_rename,
    };
}

/// Publish a failure event to the pipeline completion subject.
fn publishFailed(conn: *nats.Connection, job_id: []const u8, stage_name: []const u8, reason: []const u8) void {
    var buf: [768]u8 = undefined;
    const evt = std.fmt.bufPrint(&buf,
        "{{\"job_id\":\"{s}\",\"stage\":\"{s}\",\"status\":\"failed\",\"error\":\"{s}\"}}",
        .{ job_id, stage_name, reason },
    ) catch return;
    conn.publish(subject_completed, evt) catch {};
}

fn getStr(obj: std.json.ObjectMap, key: []const u8) ?[]const u8 {
    const val = obj.get(key) orelse return null;
    return switch (val) {
        .string => |s| s,
        else => null,
    };
}
