<?php
/**
 * 极简二维码生成器（纯 PHP，无依赖）。
 *
 * 为什么不用现成的在线二维码接口：那等于把「本站的支付链接」发给第三方服务，
 * 属于把交易信息外传，不合适。也不想为一个功能引入整套 composer 依赖，
 * 所以这里自己实现一版。
 *
 * 支持范围：字节模式（8bit）、纠错等级 M、版本 1~10（最多约 271 字节）。
 * 支付宝的 qr_code 串通常是 40~60 字节，版本 3~4 就够，完全在范围内。
 */

/** 每个版本在 M 级纠错下的数据码字数（不含纠错码） */
const QR_DATA_CODEWORDS_M = [
    1 => 16, 2 => 28, 3 => 44, 4 => 64, 5 => 86,
    6 => 108, 7 => 124, 8 => 154, 9 => 182, 10 => 216,
];
/** 每个版本 M 级每块的纠错码字数 */
const QR_EC_PER_BLOCK_M = [
    1 => 10, 2 => 16, 3 => 26, 4 => 18, 5 => 24,
    6 => 16, 7 => 18, 8 => 22, 9 => 22, 10 => 26,
];
/** M 级分块结构：[组1块数, 组1数据码字, 组2块数, 组2数据码字] */
const QR_BLOCKS_M = [
    1  => [1, 16, 0, 0],
    2  => [1, 28, 0, 0],
    3  => [1, 44, 0, 0],
    4  => [2, 32, 0, 0],
    5  => [2, 43, 0, 0],
    6  => [4, 27, 0, 0],
    7  => [4, 31, 0, 0],
    8  => [2, 38, 2, 39],
    9  => [3, 36, 2, 37],
    10 => [4, 43, 1, 44],
];
/** 各版本的对齐图案中心坐标 */
const QR_ALIGN_POS = [
    1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
    6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46],
    10 => [6, 28, 50],
];

/** GF(256) 对数/反对数表，RS 纠错要用 */
function qr_gf(): array
{
    static $t = null;
    if ($t !== null) {
        return $t;
    }
    $exp = array_fill(0, 512, 0);
    $log = array_fill(0, 256, 0);
    $x = 1;
    for ($i = 0; $i < 255; $i++) {
        $exp[$i] = $x;
        $log[$x] = $i;
        $x <<= 1;
        if ($x & 0x100) {
            $x ^= 0x11D;   // QR 用的本原多项式
        }
    }
    for ($i = 255; $i < 512; $i++) {
        $exp[$i] = $exp[$i - 255];
    }
    $t = ['exp' => $exp, 'log' => $log];
    return $t;
}

/** 生成 RS 纠错码字 */
function qr_rs(array $data, int $ecLen): array
{
    $g = qr_gf();
    $exp = $g['exp'];
    $log = $g['log'];

    // 生成多项式
    $poly = [1];
    for ($i = 0; $i < $ecLen; $i++) {
        $next = array_fill(0, count($poly) + 1, 0);
        foreach ($poly as $j => $c) {
            $next[$j]     ^= $c;
            $next[$j + 1] ^= ($c ? $exp[($log[$c] + $i) % 255] : 0);
        }
        $poly = $next;
    }

    $res = array_merge($data, array_fill(0, $ecLen, 0));
    $n   = count($data);
    for ($i = 0; $i < $n; $i++) {
        $lead = $res[$i];
        if ($lead === 0) {
            continue;
        }
        $lv = $log[$lead];
        foreach ($poly as $j => $c) {
            if ($c !== 0) {
                $res[$i + $j] ^= $exp[($log[$c] + $lv) % 255];
            }
        }
    }
    return array_slice($res, $n);
}

/**
 * 按内容长度挑最小够用的版本。
 *
 * 只支持到版本 6：版本 7 及以上还要额外写「版本信息区」的 BCH 编码，
 * 本实现没做那部分，硬生成出来扫不出来。版本 6 能装 108 字节，
 * 支付宝的 qr_code 串一般 40~60 字节，余量充足。
 * 真遇到超长内容返回 null，调用方会退回成「点击跳转支付」，不会静默出错码。
 */
function qr_pick_version(int $len): int
{
    foreach (QR_DATA_CODEWORDS_M as $v => $cap) {
        if ($v > 6) {
            break;
        }
        // 4 位模式指示 + 8 位长度 + 数据，换算成字节粗算
        if ($len + 2 <= $cap) {
            return $v;
        }
    }
    return 0;
}

/** 把文本编码成码字序列（字节模式 + 交织 + 纠错） */
function qr_encode_data(string $text, int $ver): array
{
    $bits = '';
    $bits .= '0100';                                    // 模式：字节
    $lenBits = $ver < 10 ? 8 : 16;
    $bits .= str_pad(decbin(strlen($text)), $lenBits, '0', STR_PAD_LEFT);
    for ($i = 0, $n = strlen($text); $i < $n; $i++) {
        $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
    }

    $total = QR_DATA_CODEWORDS_M[$ver] * 8;
    // 结束符最多补 4 个 0
    $bits .= str_repeat('0', min(4, max(0, $total - strlen($bits))));
    // 补到字节边界
    if (strlen($bits) % 8 !== 0) {
        $bits .= str_repeat('0', 8 - strlen($bits) % 8);
    }
    // 交替填充字节
    $pad = ['11101100', '00010001'];
    $k = 0;
    while (strlen($bits) < $total) {
        $bits .= $pad[$k++ % 2];
    }

    $codewords = [];
    foreach (str_split($bits, 8) as $b) {
        $codewords[] = bindec($b);
    }

    // 分块 + 每块算纠错
    [$c1, $d1, $c2, $d2] = QR_BLOCKS_M[$ver];
    $ecLen  = QR_EC_PER_BLOCK_M[$ver];
    $blocks = [];
    $ecs    = [];
    $p      = 0;
    for ($i = 0; $i < $c1; $i++) {
        $blk = array_slice($codewords, $p, $d1);
        $p  += $d1;
        $blocks[] = $blk;
        $ecs[]    = qr_rs($blk, $ecLen);
    }
    for ($i = 0; $i < $c2; $i++) {
        $blk = array_slice($codewords, $p, $d2);
        $p  += $d2;
        $blocks[] = $blk;
        $ecs[]    = qr_rs($blk, $ecLen);
    }

    // 交织：先按列取数据码字，再按列取纠错码字
    $out = [];
    $max = max($d1, $d2);
    for ($i = 0; $i < $max; $i++) {
        foreach ($blocks as $blk) {
            if (isset($blk[$i])) {
                $out[] = $blk[$i];
            }
        }
    }
    for ($i = 0; $i < $ecLen; $i++) {
        foreach ($ecs as $ec) {
            if (isset($ec[$i])) {
                $out[] = $ec[$i];
            }
        }
    }
    return $out;
}

/** 铺功能图案：定位、分隔、定时、对齐、格式/版本占位 */
function qr_place_function(array &$m, array &$rsv, int $size, int $ver): void
{
    $finder = function (int $r, int $c) use (&$m, &$rsv, $size) {
        for ($i = -1; $i <= 7; $i++) {
            for ($j = -1; $j <= 7; $j++) {
                $y = $r + $i;
                $x = $c + $j;
                if ($y < 0 || $x < 0 || $y >= $size || $x >= $size) {
                    continue;
                }
                $on = ($i >= 0 && $i <= 6 && ($j === 0 || $j === 6))
                   || ($j >= 0 && $j <= 6 && ($i === 0 || $i === 6))
                   || ($i >= 2 && $i <= 4 && $j >= 2 && $j <= 4);
                $m[$y][$x]   = $on ? 1 : 0;
                $rsv[$y][$x] = 1;
            }
        }
    };
    $finder(0, 0);
    $finder(0, $size - 7);
    $finder($size - 7, 0);

    // 定时图案
    for ($i = 8; $i < $size - 8; $i++) {
        $b = ($i % 2 === 0) ? 1 : 0;
        $m[6][$i] = $b; $rsv[6][$i] = 1;
        $m[$i][6] = $b; $rsv[$i][6] = 1;
    }

    // 对齐图案
    $pos = QR_ALIGN_POS[$ver];
    foreach ($pos as $r) {
        foreach ($pos as $c) {
            // 跟三个定位图案重叠的位置要跳过
            if (($r <= 8 && $c <= 8) || ($r <= 8 && $c >= $size - 9)
                || ($r >= $size - 9 && $c <= 8)) {
                continue;
            }
            for ($i = -2; $i <= 2; $i++) {
                for ($j = -2; $j <= 2; $j++) {
                    $on = (abs($i) === 2 || abs($j) === 2 || ($i === 0 && $j === 0));
                    $m[$r + $i][$c + $j]   = $on ? 1 : 0;
                    $rsv[$r + $i][$c + $j] = 1;
                }
            }
        }
    }

    // 格式信息区占位
    for ($i = 0; $i <= 8; $i++) {
        if ($i !== 6) {
            $rsv[8][$i] = 1;
            $rsv[$i][8] = 1;
        }
    }
    for ($i = 0; $i < 8; $i++) {
        $rsv[8][$size - 1 - $i] = 1;
        $rsv[$size - 1 - $i][8] = 1;
    }
    $m[$size - 8][8] = 1;      // 固定的暗模块
    $rsv[$size - 8][8] = 1;

    // 版本信息区（版本 7 起才有）
    if ($ver >= 7) {
        for ($i = 0; $i < 6; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $rsv[$size - 11 + $j][$i] = 1;
                $rsv[$i][$size - 11 + $j] = 1;
            }
        }
    }
}

/** 按 Z 字形把码字填进矩阵空位 */
function qr_place_data(array &$m, array $rsv, int $size, array $codewords): void
{
    $bits = '';
    foreach ($codewords as $c) {
        $bits .= str_pad(decbin($c), 8, '0', STR_PAD_LEFT);
    }
    $len = strlen($bits);
    $p   = 0;
    $up  = true;
    for ($col = $size - 1; $col > 0; $col -= 2) {
        if ($col === 6) {
            $col--;   // 第 6 列是定时图案，跳过
        }
        for ($k = 0; $k < $size; $k++) {
            $row = $up ? ($size - 1 - $k) : $k;
            for ($c = 0; $c < 2; $c++) {
                $x = $col - $c;
                if (!empty($rsv[$row][$x])) {
                    continue;
                }
                $m[$row][$x] = ($p < $len && $bits[$p] === '1') ? 1 : 0;
                $p++;
            }
        }
        $up = !$up;
    }
}

/** 掩码函数 0~7 */
function qr_mask_bit(int $mask, int $r, int $c): bool
{
    switch ($mask) {
        case 0: return ($r + $c) % 2 === 0;
        case 1: return $r % 2 === 0;
        case 2: return $c % 3 === 0;
        case 3: return ($r + $c) % 3 === 0;
        case 4: return (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0;
        case 5: return (($r * $c) % 2 + ($r * $c) % 3) === 0;
        case 6: return ((($r * $c) % 2 + ($r * $c) % 3) % 2) === 0;
        default: return ((($r + $c) % 2 + ($r * $c) % 3) % 2) === 0;
    }
}

/** 写格式信息（含 BCH 校验和固定掩码） */
function qr_place_format(array &$m, int $size, int $mask): void
{
    // M 级的纠错指示是 00
    $data = (0b00 << 3) | $mask;
    $v    = $data << 10;
    for ($i = 4; $i >= 0; $i--) {
        if ($v & (1 << ($i + 10))) {
            $v ^= 0b10100110111 << $i;
        }
    }
    $fmt = (($data << 10) | $v) ^ 0b101010000010010;

    // 格式信息要写两份（冗余），坐标是标准规定的固定位置。
    // 第 0 位是最低位，按下面的顺序铺开。
    for ($i = 0; $i < 15; $i++) {
        $b = ($fmt >> $i) & 1;

        // 第一份：左上角，沿第 8 行和第 8 列绕过去
        if ($i < 6) {
            $m[8][$i] = $b;
        } elseif ($i === 6) {
            $m[8][7] = $b;
        } elseif ($i === 7) {
            $m[8][8] = $b;
        } elseif ($i === 8) {
            $m[7][8] = $b;
        } else {
            $m[14 - $i][8] = $b;
        }

        // 第二份：右上和左下
        if ($i < 8) {
            $m[8][$size - 1 - $i] = $b;
        } else {
            $m[$size - 15 + $i][8] = $b;
        }
    }
}

/**
 * 生成二维码 PNG 二进制。
 *
 * @param string $text  内容
 * @param int    $scale 每个模块画多少像素
 * @param int    $pad   静区宽度（模块数），标准要求 4
 * @return string|null  PNG 数据，内容太长返回 null
 */
function qr_png(string $text, int $scale = 6, int $pad = 4): ?string
{
    $ver = qr_pick_version(strlen($text));
    if ($ver === 0) {
        return null;
    }
    $size = 17 + $ver * 4;

    $m   = array_fill(0, $size, array_fill(0, $size, 0));
    $rsv = array_fill(0, $size, array_fill(0, $size, 0));
    qr_place_function($m, $rsv, $size, $ver);
    qr_place_data($m, $rsv, $size, qr_encode_data($text, $ver));

    // 固定用掩码 2：本实现不做罚分择优，掩码 2（按列）对支付串这类内容
    // 生成的图形足够均匀，实测各家扫码 App 都能正常识别。
    $mask = 2;
    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c < $size; $c++) {
            if (empty($rsv[$r][$c]) && qr_mask_bit($mask, $r, $c)) {
                $m[$r][$c] ^= 1;
            }
        }
    }
    qr_place_format($m, $size, $mask);

    // 出图
    $px  = ($size + $pad * 2) * $scale;
    $img = imagecreate($px, $px);
    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);
    imagefilledrectangle($img, 0, 0, $px, $px, $white);
    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c < $size; $c++) {
            if ($m[$r][$c]) {
                $x = ($c + $pad) * $scale;
                $y = ($r + $pad) * $scale;
                imagefilledrectangle($img, $x, $y, $x + $scale - 1, $y + $scale - 1, $black);
            }
        }
    }
    ob_start();
    imagepng($img);
    $out = (string) ob_get_clean();
    imagedestroy($img);
    return $out;
}
