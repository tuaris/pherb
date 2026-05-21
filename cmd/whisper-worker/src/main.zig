const std = @import("std");
const nats = @import("nats");
const zio = @import("zio");
const posix = std.posix;

const log = std.log.scoped(.whisper_worker);

pub const version = "0.1.2";

const Config = struct {
    nats_url: []const u8 = "nats://127.0.0.1:4222",
    subject: []const u8 = "pherb.whisper.transcribe",
    whisper_bin: []const u8 = "/usr/local/bin/whisper-cli",
    models_dir: []const u8 = "/models/whisper",
    threads: u8 = 8,
};

/// Active job — tracks a running whisper-cli process and the NATS reply subject.
const ActiveJob = struct {
    child_pid: posix.pid_t,
    reply_to: []const u8,
    job_id: []const u8,
    out_base: []const u8,
    wav_path: ?[]const u8,
};

pub fn main() !void {
    var gpa = std.heap.GeneralPurposeAllocator(.{}){};
    defer _ = gpa.deinit();
    const allocator = gpa.allocator();

    const config = Config{};

    log.info("whisper-worker {s} starting", .{version});
    log.info("NATS: {s}", .{config.nats_url});
    log.info("subject: {s}", .{config.subject});
    log.info("whisper-cli: {s}", .{config.whisper_bin});
    log.info("models: {s}", .{config.models_dir});

    // Initialize ZIO runtime (kqueue on FreeBSD)
    const rt = try zio.Runtime.init(allocator, .{});
    defer rt.deinit();

    // Connect to NATS
    var conn = nats.Connection.init(allocator, .{});
    defer conn.deinit();

    conn.connect(config.nats_url) catch |err| {
        log.err("NATS connect failed: {}", .{err});
        std.process.exit(1);
    };

    log.info("connected to NATS", .{});

    // Subscribe synchronously
    const sub = conn.subscribeSync(config.subject) catch |err| {
        log.err("subscribe failed: {}", .{err});
        std.process.exit(1);
    };
    defer sub.deinit();

    log.info("subscribed to {s}, waiting for jobs...", .{config.subject});

    // Main loop — state machine with non-blocking child wait.
    // When idle: nextMsg blocks up to 30s (NATS handles PINGs during this time).
    // When a child is running: nextMsg uses a short 2s timeout, then we check
    // if the child exited via waitpid(WNOHANG). This keeps the NATS connection
    // alive during long transcriptions without threads or polling.
    var active_job: ?ActiveJob = null;

    while (true) {
        // Check if active child has exited (non-blocking)
        if (active_job) |*job| {
            const wait_result = posix.waitpid(job.child_pid, 1); // 1 = WNOHANG
            if (wait_result.pid != 0) {
                // Child exited — extract exit code from raw status
                const raw = wait_result.status;
                const exit_code: u32 = if (raw & 0x7f == 0) (raw >> 8) & 0xff else 255;
                completeJob(allocator, &conn, job, exit_code) catch |err| {
                    log.err("[{s}] completion failed: {}", .{ job.job_id, err });
                };
                allocator.free(job.reply_to);
                allocator.free(job.job_id);
                allocator.free(job.out_base);
                if (job.wav_path) |wp| allocator.free(wp);
                active_job = null;
            }
        }

        // Wait for NATS messages — short timeout if child is running, long if idle
        const timeout: u64 = if (active_job != null) 2000 else 30000;
        var msg = sub.nextMsg(timeout) catch |err| {
            if (err == error.Timeout) continue;
            log.err("receive error: {}", .{err});
            continue;
        };
        defer msg.deinit();

        // If we're already processing a job, ignore new messages (single worker)
        if (active_job != null) {
            log.warn("busy, ignoring message (job in progress)", .{});
            continue;
        }

        active_job = spawnJob(allocator, &conn, &config, msg) catch |err| {
            log.err("spawn failed: {}", .{err});
            continue;
        };
    }
}

/// Parse NATS message, convert audio if needed, spawn whisper-cli.
/// Returns an ActiveJob for the main loop to monitor, or null on error.
fn spawnJob(
    allocator: std.mem.Allocator,
    conn: *nats.Connection,
    config: *const Config,
    msg: *nats.Message,
) !?ActiveJob {
    const reply_to = msg.reply orelse {
        log.warn("no reply-to in message, ignoring", .{});
        return null;
    };

    const data = msg.data;
    if (data.len == 0) {
        log.warn("empty message, ignoring", .{});
        try conn.publish(reply_to, "{\"error\":\"empty message\"}");
        return null;
    }

    // Parse JSON payload
    const parsed = std.json.parseFromSlice(std.json.Value, allocator, data, .{}) catch {
        log.err("invalid JSON payload", .{});
        try conn.publish(reply_to, "{\"error\":\"invalid JSON\"}");
        return null;
    };
    defer parsed.deinit();

    const root = parsed.value.object;
    const job_id_raw = getStr(root, "job_id") orelse "unknown";
    const audio_path = getStr(root, "audio_path") orelse {
        log.err("[{s}] missing audio_path", .{job_id_raw});
        try conn.publish(reply_to, "{\"error\":\"missing audio_path\"}");
        return null;
    };
    const model = getStr(root, "model") orelse "medium.en";

    // Dupe strings we need to keep beyond this message's lifetime
    const job_id = try allocator.dupe(u8, job_id_raw);
    errdefer allocator.free(job_id);
    const reply_dupe = try allocator.dupe(u8, reply_to);
    errdefer allocator.free(reply_dupe);

    log.info("[{s}] transcribing: {s} (model={s})", .{ job_id, audio_path, model });

    // Build paths
    var model_path_buf: [512]u8 = undefined;
    const model_path = std.fmt.bufPrint(&model_path_buf, "{s}/ggml-{s}.bin", .{ config.models_dir, model }) catch unreachable;

    var out_base_buf: [512]u8 = undefined;
    const out_base_slice = std.fmt.bufPrint(&out_base_buf, "/tmp/pherb_whisper_{s}", .{job_id}) catch unreachable;
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
            try conn.publish(reply_dupe, "{\"error\":\"ffmpeg spawn failed\"}");
            allocator.free(wp);
            return null;
        };
        const ff_result = ffmpeg.wait() catch |err| {
            log.err("[{s}] ffmpeg wait failed: {}", .{ job_id, err });
            try conn.publish(reply_dupe, "{\"error\":\"ffmpeg wait failed\"}");
            allocator.free(wp);
            return null;
        };
        if (ff_result.Exited != 0) {
            log.err("[{s}] ffmpeg exited with code {d}", .{ job_id, ff_result.Exited });
            try conn.publish(reply_dupe, "{\"error\":\"ffmpeg conversion failed\"}");
            allocator.free(wp);
            return null;
        }
        wav_cleanup = wp;
        break :blk wp;
    };

    // Spawn whisper-cli (non-blocking — main loop monitors via waitpid)
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
        try conn.publish(reply_dupe, "{\"error\":\"whisper-cli spawn failed\"}");
        return null;
    };

    log.info("[{s}] whisper-cli spawned (pid {d})", .{ job_id, child.id });

    return ActiveJob{
        .child_pid = @intCast(child.id),
        .reply_to = reply_dupe,
        .job_id = job_id,
        .out_base = out_base,
        .wav_path = wav_cleanup,
    };
}

/// Called when whisper-cli exits. Reads output JSON and publishes reply.
fn completeJob(
    allocator: std.mem.Allocator,
    conn: *nats.Connection,
    job: *const ActiveJob,
    exit_code: u32,
) !void {
    // Clean up temp WAV
    if (job.wav_path) |wp| std.fs.cwd().deleteFile(wp) catch {};

    if (exit_code != 0) {
        log.err("[{s}] whisper-cli exited with code {d}", .{ job.job_id, exit_code });
        var err_buf: [256]u8 = undefined;
        const err_msg = std.fmt.bufPrint(&err_buf, "{{\"error\":\"whisper-cli exit {d}\"}}", .{exit_code}) catch unreachable;
        try conn.publish(job.reply_to, err_msg);
        return;
    }

    // Read output JSON
    var json_path_buf: [512]u8 = undefined;
    const json_path = std.fmt.bufPrint(&json_path_buf, "{s}.json", .{job.out_base}) catch unreachable;

    const json_data = std.fs.cwd().readFileAlloc(allocator, json_path, 64 * 1024 * 1024) catch |err| {
        log.err("[{s}] failed to read output: {}", .{ job.job_id, err });
        try conn.publish(job.reply_to, "{\"error\":\"no output file\"}");
        return;
    };
    defer allocator.free(json_data);

    // Clean up temp files
    std.fs.cwd().deleteFile(json_path) catch {};
    std.fs.cwd().deleteFile(job.out_base) catch {};

    log.info("[{s}] done, sending {d} bytes", .{ job.job_id, json_data.len });
    try conn.publish(job.reply_to, json_data);
}

fn getStr(obj: std.json.ObjectMap, key: []const u8) ?[]const u8 {
    const val = obj.get(key) orelse return null;
    return switch (val) {
        .string => |s| s,
        else => null,
    };
}
