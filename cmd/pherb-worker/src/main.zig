const std = @import("std");
const nats = @import("nats");
const zio = @import("zio");
const posix = std.posix;
const c = std.c;

const log = std.log.scoped(.pherb_worker);

pub const version = "0.4.0";

const Config = struct {
    nats_url: []const u8 = "nats://127.0.0.1:4222",
    subject: []const u8 = "pherb.worker.>",
    whisper_bin: []const u8 = "/usr/local/bin/whisper-cli",
    models_dir: []const u8 = "/models/whisper",
    output_dir: []const u8 = "/data/audio/outputs",
    pyannote_url: []const u8 = "http://127.0.0.1:9090",
    wav2vec2_url: []const u8 = "http://127.0.0.1:9091",
    threads: u8 = 8,
};

const subject_whisper = "pherb.worker.whisper";
const subject_pyannote = "pherb.worker.pyannote";
const subject_align = "pherb.worker.align";
const subject_status = "pherb.worker.status";
const subject_completed = "pherb.pipeline.completed";

const Stage = enum {
    whisper,
    pyannote,
    alignment,
};

/// Active job — tracks a running child process (whisper-cli or curl).
const ActiveJob = struct {
    child_pid: posix.pid_t,
    job_id: []const u8,
    stage: Stage,
    out_path: []const u8,
    cleanup_paths: [4]?[]const u8,
};

pub fn main() !void {
    var gpa = std.heap.GeneralPurposeAllocator(.{}){};
    defer _ = gpa.deinit();
    const allocator = gpa.allocator();

    const config = Config{};

    log.info("pherb-worker {s} starting", .{version});
    log.info("NATS: {s}", .{config.nats_url});
    log.info("subject: {s}", .{config.subject});
    log.info("whisper-cli: {s}", .{config.whisper_bin});
    log.info("models: {s}", .{config.models_dir});
    log.info("output: {s}", .{config.output_dir});
    log.info("pyannote: {s}", .{config.pyannote_url});
    log.info("wav2vec2: {s}", .{config.wav2vec2_url});

    // Initialize ZIO runtime (kqueue on FreeBSD)
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

    // Subscribe to wildcard — dispatches transcribe and status requests
    const sub = conn.subscribeSync(config.subject) catch |err| {
        log.err("subscribe failed: {}", .{err});
        std.process.exit(1);
    };
    defer sub.deinit();

    log.info("subscribed to {s}, waiting for jobs...", .{config.subject});

    // Main loop — event-driven via kqueue for child exit, NATS for messages.
    //
    // When idle: nextMsg blocks up to 30s (NATS library handles PINGs internally).
    // When a child is running: nextMsg uses 100ms timeout, then we check kqueue
    // for EVFILT_PROC child exit events. kqueue replaces waitpid(WNOHANG) polling —
    // the kernel delivers the exit event immediately, we just need to check between
    // NATS message fetches.
    var active_job: ?ActiveJob = null;

    while (true) {
        // Check kqueue for child exit event (non-blocking)
        if (active_job) |*job| {
            if (checkChildExit(kq, allocator, &conn, &config, job)) {
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

        // Dispatch by subject
        if (std.mem.eql(u8, msg.subject, subject_status)) {
            handleStatus(&conn, msg, active_job);
            continue;
        }

        // Determine which stage this message is for
        const stage: Stage = if (std.mem.eql(u8, msg.subject, subject_whisper))
            .whisper
        else if (std.mem.eql(u8, msg.subject, subject_pyannote))
            .pyannote
        else if (std.mem.eql(u8, msg.subject, subject_align))
            .alignment
        else {
            log.warn("unknown subject: {s}", .{msg.subject});
            continue;
        };

        // Reply busy if already processing
        if (active_job) |job| {
            replyBusy(&conn, msg, job);
            continue;
        }

        active_job = spawnStage(allocator, &conn, &config, kq, msg, stage) catch |err| {
            log.err("spawn failed: {}", .{err});
            continue;
        };
    }
}

/// Check kqueue for child exit event. Returns true if child exited and job was completed.
fn checkChildExit(kq: i32, allocator: std.mem.Allocator, conn: *nats.Connection, config: *const Config, job: *ActiveJob) bool {
    _ = config;
    var events: [1]posix.Kevent = undefined;
    const zero_timeout = posix.timespec{ .sec = 0, .nsec = 0 };
    const n = posix.kevent(kq, &.{}, &events, &zero_timeout) catch |err| {
        log.err("[{s}] kevent check failed: {}", .{ job.job_id, err });
        return false;
    };
    if (n == 0) return false;

    // EVFILT_PROC event fired — child exited. data field contains wait(2) status.
    const raw: u32 = @truncate(@as(u64, @bitCast(events[0].data)));
    const exit_code: u32 = if (raw & 0x7f == 0) (raw >> 8) & 0xff else 255;

    // Reap the zombie
    _ = posix.waitpid(job.child_pid, 1); // WNOHANG — should return immediately

    completeJob(allocator, conn, job, exit_code) catch |err| {
        log.err("[{s}] completion failed: {}", .{ job.job_id, err });
    };

    // Free allocated resources
    allocator.free(job.job_id);
    allocator.free(job.out_path);
    for (&job.cleanup_paths) |*p| {
        if (p.*) |path| {
            std.fs.cwd().deleteFile(path) catch {};
            allocator.free(path);
            p.* = null;
        }
    }
    return true;
}

/// Handle pherb.worker.status request — reply with current worker state.
fn handleStatus(conn: *nats.Connection, msg: *nats.Message, active_job: ?ActiveJob) void {
    const reply_to = msg.reply orelse return;

    if (active_job) |job| {
        var buf: [256]u8 = undefined;
        const stage_name = @tagName(job.stage);
        const status = std.fmt.bufPrint(&buf, "{{\"status\":\"busy\",\"job_id\":\"{s}\",\"stage\":\"{s}\",\"pid\":{d}}}", .{ job.job_id, stage_name, job.child_pid }) catch return;
        conn.publish(reply_to, status) catch {};
    } else {
        conn.publish(reply_to, "{\"status\":\"idle\"}") catch {};
    }
}

/// Publish busy status to the completion subject so the consumer knows immediately.
fn replyBusy(conn: *nats.Connection, msg: *nats.Message, job: ActiveJob) void {
    _ = msg;
    var buf: [512]u8 = undefined;
    const busy = std.fmt.bufPrint(&buf, "{{\"status\":\"busy\",\"active_job\":\"{s}\",\"stage\":\"{s}\"}}", .{ job.job_id, @tagName(job.stage) }) catch return;
    conn.publish(subject_completed, busy) catch {};
    log.info("busy ({s}), active job {s}", .{ @tagName(job.stage), job.job_id });
}

/// Dispatch to the appropriate stage handler based on subject.
fn spawnStage(
    allocator: std.mem.Allocator,
    conn: *nats.Connection,
    config: *const Config,
    kq: i32,
    msg: *nats.Message,
    stage: Stage,
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
        log.err("[{s}] missing audio_path", .{job_id_raw});
        publishFailed(conn, job_id_raw, stage, "missing audio_path");
        return null;
    };

    // Dupe job_id — lives beyond message lifetime
    const job_id = try allocator.dupe(u8, job_id_raw);
    errdefer allocator.free(job_id);

    return switch (stage) {
        .whisper => spawnWhisper(allocator, conn, config, kq, job_id, audio_path, root),
        .pyannote => spawnCurl(allocator, conn, config, kq, job_id, audio_path, .pyannote, null),
        .alignment => blk: {
            const transcript_path = getStr(root, "transcript_path");
            break :blk spawnCurl(allocator, conn, config, kq, job_id, audio_path, .alignment, transcript_path);
        },
    };
}

/// Spawn whisper-cli for transcription.
fn spawnWhisper(
    allocator: std.mem.Allocator,
    conn: *nats.Connection,
    config: *const Config,
    kq: i32,
    job_id: []const u8,
    audio_path: []const u8,
    root: std.json.ObjectMap,
) !?ActiveJob {
    const model = getStr(root, "model") orelse "medium.en";

    log.info("[{s}] whisper: {s} (model={s})", .{ job_id, audio_path, model });

    // Build paths
    var model_path_buf: [512]u8 = undefined;
    const model_path = std.fmt.bufPrint(&model_path_buf, "{s}/ggml-{s}.bin", .{ config.models_dir, model }) catch unreachable;

    var out_base_buf: [512]u8 = undefined;
    const out_base_slice = std.fmt.bufPrint(&out_base_buf, "/tmp/pherb_whisper_{s}", .{job_id}) catch unreachable;

    // Output destination
    var out_path_buf: [512]u8 = undefined;
    const out_path_slice = std.fmt.bufPrint(&out_path_buf, "{s}/{s}.whisper.json", .{ config.output_dir, job_id }) catch unreachable;
    const out_path = try allocator.dupe(u8, out_path_slice);
    errdefer allocator.free(out_path);

    const out_base = try allocator.dupe(u8, out_base_slice);
    errdefer allocator.free(out_base);

    var threads_buf: [4]u8 = undefined;
    const threads_str = std.fmt.bufPrint(&threads_buf, "{d}", .{config.threads}) catch unreachable;

    // Convert to WAV if needed (ffmpeg is fast, blocking here is fine)
    var wav_cleanup: ?[]const u8 = null;
    const wav_path: []const u8 = blk: {
        if (std.mem.endsWith(u8, audio_path, ".wav")) {
            break :blk audio_path;
        }
        var wav_buf: [512]u8 = undefined;
        const wp_slice = std.fmt.bufPrint(&wav_buf, "/tmp/pherb_wav_{s}.wav", .{job_id}) catch unreachable;
        const wp = try allocator.dupe(u8, wp_slice);

        log.info("[{s}] converting to WAV...", .{job_id});
        var ffmpeg = std.process.Child.init(&.{
            "/usr/local/bin/ffmpeg", "-y", "-i", audio_path,
            "-ar", "16000", "-ac", "1", wp,
        }, allocator);
        ffmpeg.stderr_behavior = .Ignore;
        ffmpeg.stdout_behavior = .Ignore;
        ffmpeg.spawn() catch |err| {
            log.err("[{s}] ffmpeg spawn failed: {}", .{ job_id, err });
            publishFailed(conn, job_id, .whisper, "ffmpeg spawn failed");
            allocator.free(wp);
            return null;
        };
        const ff_result = ffmpeg.wait() catch |err| {
            log.err("[{s}] ffmpeg wait failed: {}", .{ job_id, err });
            publishFailed(conn, job_id, .whisper, "ffmpeg wait failed");
            allocator.free(wp);
            return null;
        };
        if (ff_result.Exited != 0) {
            log.err("[{s}] ffmpeg exited with code {d}", .{ job_id, ff_result.Exited });
            publishFailed(conn, job_id, .whisper, "ffmpeg conversion failed");
            allocator.free(wp);
            return null;
        }
        wav_cleanup = wp;
        break :blk wp;
    };

    // Spawn whisper-cli (non-blocking — main loop monitors via kqueue)
    var child = std.process.Child.init(&.{
        config.whisper_bin,
        "-m",  model_path,
        "-f",  wav_path,
        "-t",  threads_str,
        "--output-json-full",
        "-of", out_base,
    }, allocator);
    child.stderr_behavior = .Ignore;
    child.stdout_behavior = .Ignore;

    child.spawn() catch |err| {
        log.err("[{s}] whisper-cli spawn failed: {}", .{ job_id, err });
        publishFailed(conn, job_id, .whisper, "whisper-cli spawn failed");
        return null;
    };

    const child_pid: posix.pid_t = @intCast(child.id);
    log.info("[{s}] whisper-cli spawned (pid {d})", .{ job_id, child_pid });

    registerKqueue(kq, child_pid, job_id);

    return ActiveJob{
        .child_pid = child_pid,
        .job_id = job_id,
        .stage = .whisper,
        .out_path = out_path,
        .cleanup_paths = .{ out_base, wav_cleanup, null, null },
    };
}

/// Spawn curl for pyannote or wav2vec2 HTTP stage.
fn spawnCurl(
    allocator: std.mem.Allocator,
    conn: *nats.Connection,
    config: *const Config,
    kq: i32,
    job_id: []const u8,
    audio_path: []const u8,
    stage: Stage,
    transcript_path: ?[]const u8,
) !?ActiveJob {
    const stage_name = @tagName(stage);

    // Determine URL and output extension
    var url_buf: [512]u8 = undefined;
    const url: []const u8 = switch (stage) {
        .pyannote => std.fmt.bufPrint(&url_buf, "{s}/diarize", .{config.pyannote_url}) catch unreachable,
        .alignment => std.fmt.bufPrint(&url_buf, "{s}/align", .{config.wav2vec2_url}) catch unreachable,
        .whisper => unreachable,
    };
    const ext: []const u8 = switch (stage) {
        .pyannote => "pyannote.json",
        .alignment => "align.json",
        .whisper => unreachable,
    };

    // Output path
    var out_path_buf: [512]u8 = undefined;
    const out_path_slice = std.fmt.bufPrint(&out_path_buf, "{s}/{s}.{s}", .{ config.output_dir, job_id, ext }) catch unreachable;
    const out_path = try allocator.dupe(u8, out_path_slice);
    errdefer allocator.free(out_path);

    log.info("[{s}] {s}: {s}", .{ job_id, stage_name, audio_path });

    // Build curl form field for file upload
    var file_form_buf: [512]u8 = undefined;
    const file_form = std.fmt.bufPrint(&file_form_buf, "file=@{s}", .{audio_path}) catch unreachable;

    // Build argv dynamically based on stage
    var argv_buf: [16][]const u8 = undefined;
    var argc: usize = 0;

    argv_buf[argc] = "/usr/local/bin/curl";
    argc += 1;
    argv_buf[argc] = "--fail-with-body";
    argc += 1;
    argv_buf[argc] = "-s";
    argc += 1;
    argv_buf[argc] = "-o";
    argc += 1;
    argv_buf[argc] = out_path;
    argc += 1;
    argv_buf[argc] = "-F";
    argc += 1;
    argv_buf[argc] = file_form;
    argc += 1;

    // For align stage, add transcript form field
    var transcript_form_buf: [512]u8 = undefined;
    if (stage == .alignment) {
        if (transcript_path) |tp| {
            const tf = std.fmt.bufPrint(&transcript_form_buf, "transcript=<{s}", .{tp}) catch unreachable;
            argv_buf[argc] = "-F";
            argc += 1;
            argv_buf[argc] = tf;
            argc += 1;
        }
    }

    argv_buf[argc] = url;
    argc += 1;

    const argv = argv_buf[0..argc];

    var child = std.process.Child.init(argv, allocator);
    child.stderr_behavior = .Ignore;
    child.stdout_behavior = .Ignore;

    child.spawn() catch |err| {
        log.err("[{s}] curl spawn failed for {s}: {}", .{ job_id, stage_name, err });
        publishFailed(conn, job_id, stage, "curl spawn failed");
        return null;
    };

    const child_pid: posix.pid_t = @intCast(child.id);
    log.info("[{s}] curl spawned for {s} (pid {d})", .{ job_id, stage_name, child_pid });

    registerKqueue(kq, child_pid, job_id);

    return ActiveJob{
        .child_pid = child_pid,
        .job_id = job_id,
        .stage = stage,
        .out_path = out_path,
        .cleanup_paths = .{ null, null, null, null },
    };
}

/// Register child PID with kqueue for exit notification.
fn registerKqueue(kq: i32, child_pid: posix.pid_t, job_id: []const u8) void {
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
}

/// Called when a child process exits. Handles output and publishes completion event.
fn completeJob(
    allocator: std.mem.Allocator,
    conn: *nats.Connection,
    job: *const ActiveJob,
    exit_code: u32,
) !void {
    _ = allocator;
    const stage_name = @tagName(job.stage);

    if (exit_code != 0) {
        log.err("[{s}] {s} exited with code {d}", .{ job.job_id, stage_name, exit_code });
        var err_buf: [128]u8 = undefined;
        const err_detail = std.fmt.bufPrint(&err_buf, "{s} exit {d}", .{ stage_name, exit_code }) catch "process failed";
        publishFailed(conn, job.job_id, job.stage, err_detail);
        return;
    }

    // For whisper stage: move output from tmp to output_dir
    if (job.stage == .whisper) {
        // whisper-cli writes to {cleanup_paths[0]}.json (out_base.json)
        if (job.cleanup_paths[0]) |out_base| {
            var src_buf: [512]u8 = undefined;
            const src_path = std.fmt.bufPrint(&src_buf, "{s}.json", .{out_base}) catch unreachable;
            std.fs.cwd().rename(src_path, job.out_path) catch |err| {
                log.err("[{s}] failed to move whisper output: {}", .{ job.job_id, err });
                publishFailed(conn, job.job_id, job.stage, "failed to move output file");
                return;
            };
        }
    }
    // For curl stages (pyannote, align): output already written directly to out_path by curl -o

    log.info("[{s}] {s} done, output at {s}", .{ job.job_id, stage_name, job.out_path });

    // Publish completion event with stage info
    var evt_buf: [768]u8 = undefined;
    const evt = std.fmt.bufPrint(&evt_buf,
        "{{\"job_id\":\"{s}\",\"stage\":\"{s}\",\"status\":\"completed\",\"output_path\":\"{s}\"}}",
        .{ job.job_id, stage_name, job.out_path },
    ) catch unreachable;
    conn.publish(subject_completed, evt) catch |err| {
        log.err("[{s}] failed to publish completion: {}", .{ job.job_id, err });
    };
}

/// Publish a failure event to the pipeline completion subject.
fn publishFailed(conn: *nats.Connection, job_id: []const u8, stage: Stage, reason: []const u8) void {
    var buf: [768]u8 = undefined;
    const evt = std.fmt.bufPrint(&buf,
        "{{\"job_id\":\"{s}\",\"stage\":\"{s}\",\"status\":\"failed\",\"error\":\"{s}\"}}",
        .{ job_id, @tagName(stage), reason },
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
