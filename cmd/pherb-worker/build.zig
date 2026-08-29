const std = @import("std");

pub fn build(b: *std.Build) void {
    const target = b.standardTargetOptions(.{});
    const optimize = b.standardOptimizeOption(.{});

    const nats_dep = b.dependency("nats", .{
        .target = target,
        .optimize = optimize,
    });

    const zio_dep = nats_dep.builder.dependency("zio", .{
        .target = target,
        .optimize = optimize,
    });

    const otel_dep = b.dependency("opentelemetry", .{
        .target = target,
        .optimize = optimize,
    });

    const exe = b.addExecutable(.{
        .name = "pherb-worker",
        .root_module = b.createModule(.{
            .root_source_file = b.path("src/main.zig"),
            .target = target,
            .optimize = optimize,
            .imports = &.{
                .{ .name = "nats", .module = nats_dep.module("nats") },
                .{ .name = "zio", .module = zio_dep.module("zio") },
                .{ .name = "opentelemetry-sdk", .module = otel_dep.module("sdk") },
            },
        }),
    });

    b.installArtifact(exe);

    const run_cmd = b.addRunArtifact(exe);
    run_cmd.step.dependOn(b.getInstallStep());
    if (b.args) |args| {
        run_cmd.addArgs(args);
    }

    const run_step = b.step("run", "Run pherb-worker");
    run_step.dependOn(&run_cmd.step);
}
