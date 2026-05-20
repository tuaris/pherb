const std = @import("std");
const nats = @import("nats");

const log = std.log.scoped(.whisper_worker);

pub const version = "0.1.1";

const Config = struct {
    nats_url: []const u8 = "nats://127.0.0.1:4222",
    subject: []const u8 = "pherb.whisper.transcribe",
    whisper_bin: []const u8 = "/usr/local/bin/whisper-cli",
    models_dir: []const u8 = "/models/whisper",
    threads: u8 = 8,
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

    // Connect to NATS
    var conn = nats.Connection.init(allocator, .{});
    defer conn.deinit();

    conn.connect(config.nats_url) catch |err| {
        log.err("NATS connect failed: {}", .{err});
        std.process.exit(1);
    };

    log.info("connected to NATS", .{});

    // Subscribe synchronously — nextMsg blocks on kqueue, zero CPU when idle
    // The PHP consumer uses request/reply — messages arrive with a reply-to subject
    const sub = conn.subscribeSync(config.subject) catch |err| {
        log.err("subscribe failed: {}", .{err});
        std.process.exit(1);
    };
    defer sub.deinit();

    log.info("subscribed to {s}, waiting for jobs...", .{config.subject});

    // Main loop — nextMsg blocks on kqueue, zero CPU when idle
    while (true) {
        var msg = sub.nextMsg(30000) catch |err| {
            if (err == error.Timeout) continue;
            log.err("receive error: {}", .{err});
            continue;
        };
        defer msg.deinit();

        handleMessage(allocator, &conn, &config, msg) catch |err| {
            log.err("job failed: {}", .{err});
        };
    }
}

fn handleMessage(
    allocator: std.mem.Allocator,
    conn: *nats.Connection,
    config: *const Config,
    msg: *nats.Message,
) !void {
    const reply_to = msg.reply orelse {
        log.warn("no reply-to in message, ignoring", .{});
        return;
    };

    const data = msg.data;
    if (data.len == 0) {
        log.warn("empty message, ignoring", .{});
        try conn.publish(reply_to, "{\"error\":\"empty message\"}");
        return;
    }

    // Parse JSON payload: {"job_id": "...", "audio_path": "...", "model": "medium.en"}
    const parsed = std.json.parseFromSlice(std.json.Value, allocator, data, .{}) catch {
        log.err("invalid JSON payload", .{});
        try conn.publish(reply_to, "{\"error\":\"invalid JSON\"}");
        return;
    };
    defer parsed.deinit();

    const root = parsed.value.object;
    const job_id = getStr(root, "job_id") orelse "unknown";
    const audio_path = getStr(root, "audio_path") orelse {
        log.err("[{s}] missing audio_path", .{job_id});
        try conn.publish(reply_to, "{\"error\":\"missing audio_path\"}");
        return;
    };
    const model = getStr(root, "model") orelse "medium.en";

    log.info("[{s}] transcribing: {s} (model={s})", .{ job_id, audio_path, model });

    // Build model path
    var model_path_buf: [512]u8 = undefined;
    const model_path = std.fmt.bufPrint(&model_path_buf, "{s}/ggml-{s}.bin", .{ config.models_dir, model }) catch unreachable;

    // Build output temp path (whisper-cli appends .json)
    var out_base_buf: [512]u8 = undefined;
    const out_base = std.fmt.bufPrint(&out_base_buf, "/tmp/pherb_whisper_{s}", .{job_id}) catch unreachable;

    // Build thread count string
    var threads_buf: [4]u8 = undefined;
    const threads_str = std.fmt.bufPrint(&threads_buf, "{d}", .{config.threads}) catch unreachable;

    // Run whisper-cli
    var child = std.process.Child.init(&.{
        config.whisper_bin,
        "-m",  model_path,
        "-f",  audio_path,
        "-t",  threads_str,
        "--output-json-full",
        "-of", out_base,
    }, allocator);
    child.stderr_behavior = .Ignore;
    child.stdout_behavior = .Ignore;

    child.spawn() catch |err| {
        log.err("[{s}] failed to spawn whisper-cli: {}", .{ job_id, err });
        var err_buf: [256]u8 = undefined;
        const err_msg = std.fmt.bufPrint(&err_buf, "{{\"error\":\"spawn failed: {s}\"}}", .{@errorName(err)}) catch unreachable;
        try conn.publish(reply_to, err_msg);
        return;
    };

    const result = child.wait() catch |err| {
        log.err("[{s}] whisper-cli wait failed: {}", .{ job_id, err });
        try conn.publish(reply_to, "{\"error\":\"wait failed\"}");
        return;
    };

    if (result.Exited != 0) {
        log.err("[{s}] whisper-cli exited with code {d}", .{ job_id, result.Exited });
        var err_buf: [256]u8 = undefined;
        const err_msg = std.fmt.bufPrint(&err_buf, "{{\"error\":\"whisper-cli exit {d}\"}}", .{result.Exited}) catch unreachable;
        try conn.publish(reply_to, err_msg);
        return;
    }

    // Read the output JSON file
    var json_path_buf: [512]u8 = undefined;
    const json_path = std.fmt.bufPrint(&json_path_buf, "{s}.json", .{out_base}) catch unreachable;

    const json_data = std.fs.cwd().readFileAlloc(allocator, json_path, 64 * 1024 * 1024) catch |err| {
        log.err("[{s}] failed to read output: {}", .{ job_id, err });
        try conn.publish(reply_to, "{\"error\":\"no output file\"}");
        return;
    };
    defer allocator.free(json_data);

    // Clean up temp files
    std.fs.cwd().deleteFile(json_path) catch {};
    std.fs.cwd().deleteFile(out_base) catch {};

    log.info("[{s}] done, sending {d} bytes", .{ job_id, json_data.len });

    // Publish result back to the requester
    try conn.publish(reply_to, json_data);
}

fn getStr(obj: std.json.ObjectMap, key: []const u8) ?[]const u8 {
    const val = obj.get(key) orelse return null;
    return switch (val) {
        .string => |s| s,
        else => null,
    };
}
