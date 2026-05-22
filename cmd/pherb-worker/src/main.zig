const std = @import("std");
const nats = @import("nats");
const posix = std.posix;
const c = std.c;
const zio = @import("zio");

const log = std.log.scoped(.pherb_worker);

pub const version = "0.6.0";

const subject_status = "pherb.worker.status";
const subject_completed = "pherb.pipeline.completed";

const max_stages = 16;
const max_argv = 32;

const StageEntry = struct {
    subject: []const u8,
    command_template: []const u8,
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
                config.stages[config.stage_count] = StageEntry{
                    .subject = try allocator.dupe(u8, key),
                    .command_template = try allocator.dupe(u8, val),
                };
                config.stage_count += 1;
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

    fn findCommand(self: *const Config, subject: []const u8) ?[]const u8 {
        for (self.stages[0..self.stage_count]) |entry| {
            if (entry) |e| {
                if (std.mem.eql(u8, e.subject, subject)) return e.command_template;
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
            log.info("  {s} → {s}", .{ e.subject, e.command_template });
        }
    }

    // Initialize ZIO runtime (required by nats library — uses kqueue on FreeBSD)
    const rt = try zio.Runtime.init(allocator, .{});
    defer rt.deinit();

    // Create kqueue for event-driven child process monitoring
    const kq = try posix.kqueue();
    defer posix.close(kq);

    // Connect to NATS
    var conn = nats.Connection.init(allocator, .{});
    defer conn.deinit();

    conn.connect(config.nats_url) catch |err| {
        log.err("NATS connect failed: {}", .{err});
        std.process.exit(1);
    };

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

        // Status request
        if (std.mem.eql(u8, msg.subject, subject_status)) {
            handleStatus(&conn, msg, active_job);
            continue;
        }

        // Reply busy if already processing
        if (active_job) |job| {
            replyBusy(&conn, &job);
            continue;
        }

        // Look up command for this subject — ignore unconfigured subjects
        const command_template = config.findCommand(msg.subject) orelse {
            continue;
        };

        active_job = spawnCommand(allocator, &conn, kq, msg, command_template) catch |err| {
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
    return true;
}

/// Handle pherb.worker.status request — reply with current worker state.
fn handleStatus(conn: *nats.Connection, msg: *nats.Message, active_job: ?ActiveJob) void {
    const reply_to = msg.reply orelse return;

    if (active_job) |job| {
        var buf: [256]u8 = undefined;
        const status = std.fmt.bufPrint(&buf, "{{\"status\":\"busy\",\"job_id\":\"{s}\",\"stage\":\"{s}\",\"pid\":{d}}}", .{ job.job_id, job.stage_name, job.child_pid }) catch return;
        conn.publish(reply_to, status) catch {};
    } else {
        conn.publish(reply_to, "{\"status\":\"idle\"}") catch {};
    }
}

/// Publish busy status to the completion subject.
fn replyBusy(conn: *nats.Connection, job: *const ActiveJob) void {
    var buf: [512]u8 = undefined;
    const busy = std.fmt.bufPrint(&buf, "{{\"status\":\"busy\",\"active_job\":\"{s}\",\"stage\":\"{s}\"}}", .{ job.job_id, job.stage_name }) catch return;
    conn.publish(subject_completed, busy) catch {};
    log.info("busy ({s}), active job {s}", .{ job.stage_name, job.job_id });
}

/// Spawn a command from the template, expanding variables from the NATS payload.
fn spawnCommand(
    allocator: std.mem.Allocator,
    conn: *nats.Connection,
    kq: i32,
    msg: *nats.Message,
    command_template: []const u8,
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
    const audio_path = getStr(root, "audio_path") orelse {
        log.err("[{s}] missing audio_path in payload", .{job_id_raw});
        return null;
    };
    const output_path_raw = getStr(root, "output_path") orelse {
        log.err("[{s}] missing output_path in payload", .{job_id_raw});
        return null;
    };

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

    log.info("[{s}] {s}: {s} → {s}", .{ job_id, stage_name, audio_path, output_path });

    // Expand template variables and split into argv
    var argv_buf: [max_argv][]const u8 = undefined;
    var argc: usize = 0;
    var alloc_tracker: [max_argv]bool = .{false} ** max_argv;

    var token_iter = std.mem.splitScalar(u8, command_template, ' ');
    while (token_iter.next()) |token| {
        if (token.len == 0) continue;
        if (argc >= max_argv) break;

        const expanded = expandVar(allocator, token, job_id, audio_path, output_path_raw) catch |err| {
            log.err("[{s}] template expansion failed: {}", .{ job_id, err });
            // Free any previously allocated argv entries
            for (argv_buf[0..argc], alloc_tracker[0..argc]) |arg, was_alloc| {
                if (was_alloc) allocator.free(arg);
            }
            publishFailed(conn, job_id, stage_name, "template expansion failed");
            return null;
        };
        argv_buf[argc] = expanded.str;
        alloc_tracker[argc] = expanded.allocated;
        argc += 1;
    }

    if (argc == 0) {
        log.err("[{s}] empty command after expansion", .{job_id});
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
        for (argv_buf[0..argc], alloc_tracker[0..argc]) |arg, was_alloc| {
            if (was_alloc) allocator.free(arg);
        }
        return null;
    };

    // Free expanded argv (child has inherited/copied the data)
    for (argv_buf[0..argc], alloc_tracker[0..argc]) |arg, was_alloc| {
        if (was_alloc) allocator.free(arg);
    }

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
    };
}

const ExpandResult = struct {
    str: []const u8,
    allocated: bool,
};

/// Expand {audio_path}, {output_path}, {job_id} in a single token.
fn expandVar(allocator: std.mem.Allocator, token: []const u8, job_id: []const u8, audio_path: []const u8, output_path: []const u8) !ExpandResult {
    if (std.mem.eql(u8, token, "{audio_path}")) {
        return .{ .str = audio_path, .allocated = false };
    }
    if (std.mem.eql(u8, token, "{output_path}")) {
        return .{ .str = output_path, .allocated = false };
    }
    if (std.mem.eql(u8, token, "{job_id}")) {
        return .{ .str = job_id, .allocated = false };
    }
    // Check for embedded variables (e.g., prefix{job_id}suffix)
    if (std.mem.indexOf(u8, token, "{")) |_| {
        // Calculate total size needed
        var total_len: usize = 0;
        var pos: usize = 0;
        while (pos < token.len) {
            if (token[pos] == '{') {
                const end = std.mem.indexOfScalarPos(u8, token, pos, '}') orelse {
                    total_len += 1;
                    pos += 1;
                    continue;
                };
                const var_name = token[pos + 1 .. end];
                if (std.mem.eql(u8, var_name, "audio_path")) {
                    total_len += audio_path.len;
                } else if (std.mem.eql(u8, var_name, "output_path")) {
                    total_len += output_path.len;
                } else if (std.mem.eql(u8, var_name, "job_id")) {
                    total_len += job_id.len;
                } else {
                    total_len += (end - pos + 1);
                }
                pos = end + 1;
            } else {
                total_len += 1;
                pos += 1;
            }
        }

        // Build the expanded string
        const buf = try allocator.alloc(u8, total_len);
        var write_pos: usize = 0;
        pos = 0;
        while (pos < token.len) {
            if (token[pos] == '{') {
                const end = std.mem.indexOfScalarPos(u8, token, pos, '}') orelse {
                    buf[write_pos] = token[pos];
                    write_pos += 1;
                    pos += 1;
                    continue;
                };
                const var_name = token[pos + 1 .. end];
                const replacement: []const u8 = if (std.mem.eql(u8, var_name, "audio_path"))
                    audio_path
                else if (std.mem.eql(u8, var_name, "output_path"))
                    output_path
                else if (std.mem.eql(u8, var_name, "job_id"))
                    job_id
                else blk: {
                    const literal = token[pos .. end + 1];
                    @memcpy(buf[write_pos .. write_pos + literal.len], literal);
                    write_pos += literal.len;
                    pos = end + 1;
                    break :blk "";
                };
                if (replacement.len > 0) {
                    @memcpy(buf[write_pos .. write_pos + replacement.len], replacement);
                    write_pos += replacement.len;
                    pos = end + 1;
                }
            } else {
                buf[write_pos] = token[pos];
                write_pos += 1;
                pos += 1;
            }
        }
        return .{ .str = buf[0..write_pos], .allocated = true };
    }
    return .{ .str = token, .allocated = false };
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
