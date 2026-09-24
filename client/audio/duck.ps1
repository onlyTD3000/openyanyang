# 压低其他程序音量，响完再恢复。
# 走 Windows Core Audio 的 ISimpleAudioVolume，按会话精确控制，
# 不动系统总音量，也不碰用户的播放/暂停状态。
#
# 常驻运行：启动时编译好 COM 声明，之后从 stdin 读指令。
# 这样压低的响应是毫秒级；每次新起进程要等 Add-Type 编译一两秒，赶不上响铃。
#
# 指令格式（一行一条）：
#   duck <系数>   压低到原音量的 <系数> 倍（如 0.2）
#   restore       恢复到压低前的音量
#   quit          恢复并退出
# 什么时候恢复由 Node 侧计时决定，这边不管时间。
$ErrorActionPreference = 'Stop'
Add-Type -TypeDefinition @"
using System;
using System.Runtime.InteropServices;
// 只声明用得到的方法。COM 接口的方法顺序必须和头文件里完全一致，
// 不用的也要占位，否则后面的方法会调错槽位。
[Guid("BCDE0395-E52F-467C-8E3D-C4579291692E")]
[ClassInterface(ClassInterfaceType.None)]
[ComImport]
internal class MMDeviceEnumerator { }
[Guid("A95664D2-9614-4F35-A746-DE8DB63617E6")]
[InterfaceType(ComInterfaceType.InterfaceIsIUnknown)]
[ComImport]
internal interface IMMDeviceEnumerator {
    int NotImpl1();
    // dataFlow: 0=渲染(输出) 1=采集  role: 0=控制台 1=多媒体 2=通信
    int GetDefaultAudioEndpoint(int dataFlow, int role, out IMMDevice device);
}
[Guid("D666063F-1587-4E43-81F1-B948E807363F")]
[InterfaceType(ComInterfaceType.InterfaceIsIUnknown)]
[ComImport]
internal interface IMMDevice {
    int Activate(ref Guid iid, int clsCtx, IntPtr activationParams,
                 [MarshalAs(UnmanagedType.IUnknown)] out object iface);
}
[Guid("BFA971F1-4D5E-40BB-935E-967039BFBEE4")]
[InterfaceType(ComInterfaceType.InterfaceIsIUnknown)]
[ComImport]
internal interface IAudioSessionManager2 {
    int NotImpl1();
    int NotImpl2();
    int GetSessionEnumerator(out IAudioSessionEnumerator SessionEnum);
}
[Guid("E2F5BB11-0570-40CA-ACDD-3AA01277DEE8")]
[InterfaceType(ComInterfaceType.InterfaceIsIUnknown)]
[ComImport]
internal interface IAudioSessionEnumerator {
    int GetCount(out int SessionCount);
    int GetSession(int SessionCount, out IAudioSessionControl Session);
}
[Guid("F4B1A599-7266-4319-A8CA-E70ACB11E8CD")]
[InterfaceType(ComInterfaceType.InterfaceIsIUnknown)]
[ComImport]
internal interface IAudioSessionControl {
    int NotImpl1();
    int GetState(out int state);       // 0=非活动 1=活动 2=已过期
}
[Guid("BFB7FF88-7239-4FC9-8FA2-07C950BE9C6D")]
[InterfaceType(ComInterfaceType.InterfaceIsIUnknown)]
[ComImport]
internal interface IAudioSessionControl2 {
    int NotImpl1();
    int GetState(out int state);
    int NotImpl3();
    int NotImpl4();
    int NotImpl5();
    int NotImpl6();
    int NotImpl7();
    int NotImpl8();
    int NotImpl9();
    int NotImpl10();
    int GetProcessId(out int pid);
    int IsSystemSoundsSession();       // S_OK(0) 表示是系统声音会话
}
[Guid("87CE5498-68D6-44E5-9215-6DA47EF883D8")]
[InterfaceType(ComInterfaceType.InterfaceIsIUnknown)]
[ComImport]
internal interface ISimpleAudioVolume {
    int SetMasterVolume(float level, ref Guid eventContext);
    int GetMasterVolume(out float level);
    int SetMute(bool mute, ref Guid eventContext);
    int GetMute(out bool mute);
}
public class 音量 {
    // 记下改过谁、原值多少，恢复时照原样写回去。
    // 只存这一轮压低时抓到的会话，中途新起的播放器不动它。
    static System.Collections.Generic.List<object> 改过 =
        new System.Collections.Generic.List<object>();
    static System.Collections.Generic.List<float> 原值 =
        new System.Collections.Generic.List<float>();
    static ISimpleAudioVolume[] 取会话(int 自己pid) {
        var 出 = new System.Collections.Generic.List<ISimpleAudioVolume>();
        var 枚 = (IMMDeviceEnumerator)(new MMDeviceEnumerator());
        IMMDevice 设备;
        // role 用 1(多媒体)：音乐视频走的是这条，跟系统提示音分开
        if (枚.GetDefaultAudioEndpoint(0, 1, out 设备) != 0) return 出.ToArray();
        var iid = typeof(IAudioSessionManager2).GUID;
        object o;
        if (设备.Activate(ref iid, 23, IntPtr.Zero, out o) != 0) return 出.ToArray();
        var 管 = (IAudioSessionManager2)o;
        IAudioSessionEnumerator 会话枚;
        if (管.GetSessionEnumerator(out 会话枚) != 0) return 出.ToArray();
        int n;
        if (会话枚.GetCount(out n) != 0) return 出.ToArray();
        for (int i = 0; i < n; i++) {
            IAudioSessionControl c;
            if (会话枚.GetSession(i, out c) != 0) continue;
            var c2 = c as IAudioSessionControl2;
            if (c2 == null) continue;
            int 状态;
            // 只动正在出声的（状态 1）。非活动的会话改了没意义，
            // 而且恢复时它可能已经消失，白留一条垃圾记录。
            if (c2.GetState(out 状态) != 0 || 状态 != 1) continue;
            // 跳过系统声音会话：那里面就有我们自己的提示音，
            // 压了等于自己听不见，本末倒置。
            if (c2.IsSystemSoundsSession() == 0) continue;
            int pid;
            if (c2.GetProcessId(out pid) == 0 && pid == 自己pid) continue;
            var v = c as ISimpleAudioVolume;
            if (v != null) 出.Add(v);
        }
        return 出.ToArray();
    }
    public static int 压低(float 系数, int 自己pid) {
        恢复();
        var 空 = Guid.Empty;
        foreach (var v in 取会话(自己pid)) {
            float 原;
            if (v.GetMasterVolume(out 原) != 0) continue;
            if (原 <= 0.001f) continue;      // 本来就静音的不碰
            if (v.SetMasterVolume(原 * 系数, ref 空) != 0) continue;
            改过.Add(v);
            原值.Add(原);
        }
        return 改过.Count;
    }
    public static void 恢复() {
        var 空 = Guid.Empty;
        for (int i = 0; i < 改过.Count; i++) {
            try {
                var v = 改过[i] as ISimpleAudioVolume;
                if (v != null) v.SetMasterVolume(原值[i], ref 空);
            } catch { }   // 播放器已退出会抛，忽略即可
        }
        改过.Clear();
        原值.Clear();
    }
}
"@
# ---------- 常驻循环 ----------
# 参数 0 是 Electron 主进程的 PID，用来跳过自己的会话，不然会把提示音自己压掉。
$自己pid = 0
if ($args.Count -ge 1) { $自己pid = [int]$args[0] }
# 编译完才报 ready。Node 侧等到这一行再认为可用，
# 在此之前的压低请求直接跳过，不排队等（等到了铃也响完了）。
[Console]::Out.WriteLine('ready')
[Console]::Out.Flush()
while ($true) {
    $行 = [Console]::In.ReadLine()
    # stdin 关掉说明父进程没了。这时必须先恢复音量再退，
    # 否则 Electron 崩溃那一刻用户的音量就永久留在压低状态了。
    if ($null -eq $行) { [音量]::恢复(); break }
    $行 = $行.Trim()
    if ($行 -eq '') { continue }
    $段 = $行.Split(' ')
    switch ($段[0]) {
        'duck' {
            $系数 = 0.2
            if ($段.Count -ge 2) { $系数 = [float]$段[1] }
            try {
                $数 = [音量]::压低($系数, $自己pid)
                [Console]::Out.WriteLine("ducked $数")
            } catch {
                [Console]::Out.WriteLine('err ' + $_.Exception.Message)
            }
            [Console]::Out.Flush()
        }
        'restore' {
            try { [音量]::恢复(); [Console]::Out.WriteLine('restored') }
            catch { [Console]::Out.WriteLine('err ' + $_.Exception.Message) }
            [Console]::Out.Flush()
        }
        'quit' { [音量]::恢复(); return }
        default { [Console]::Out.WriteLine('unknown'); [Console]::Out.Flush() }
    }
}
