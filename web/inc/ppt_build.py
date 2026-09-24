#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
PPT 生成器：读一份 JSON 大纲，产出 .pptx。

设计取舍：
  1. 不用 python-pptx 自带的占位符版式。那套版式的字号、位置随模板变，
     中文标题很容易溢出框。这里全部手动摆文本框，位置自己算，效果可控。
  2. 配色做成几套预设，由大纲指定或按主题哈希挑一套。让连续生成的多份 PPT
     不会长得一模一样，又不至于每页颜色乱跳。
  3. 只认结构化输入。AI 那边负责把任意主题拆成「标题 + 若干页 + 每页要点」，
     这里只管排版，不做任何内容生成——职责分开，出问题好定位。

用法：
    python3 ppt_build.py <大纲json路径> <输出pptx路径>
成功时 stdout 打印一行 JSON：{"ok":true,"slides":页数}
失败时 stdout 打印 {"ok":false,"error":"原因"}，退出码非 0。
"""
import json
import sys
import hashlib

from pptx import Presentation
from pptx.util import Inches, Pt, Emu
from pptx.dml.color import RGBColor
from pptx.enum.text import PP_ALIGN, MSO_ANCHOR

# 16:9，现在的投影和屏幕基本都是这个比例
幻灯片宽 = Inches(13.333)
幻灯片高 = Inches(7.5)

# 配色预设：主色、深色（标题字）、浅色（背景块）、灰色（正文）
配色表 = [
    {"主": "1F5EFF", "深": "0B2A6B", "浅": "EEF3FF", "灰": "44506B", "名": "蓝"},
    {"主": "0F9D58", "深": "0B5132", "浅": "EAF7F0", "灰": "42544B", "名": "绿"},
    {"主": "7B4DFF", "深": "3A1D7A", "浅": "F2EEFF", "灰": "4B4560", "名": "紫"},
    {"主": "E8710A", "深": "7A3B04", "浅": "FFF3E8", "灰": "5C4A3A", "名": "橙"},
    {"主": "0B8AAD", "深": "064457", "浅": "E8F6FA", "灰": "3D4F55", "名": "青"},
]

# 中文字体优先级。服务器上不一定装了这些，但字体名是写进 pptx 文件的，
# 真正渲染发生在客户打开文件的机器上，所以这里只要写常见字体名就行。
中文字体 = "微软雅黑"


def 取色(十六进制):
    return RGBColor.from_string(十六进制)


def 挑配色(大纲):
    """大纲指定了就用指定的，否则按标题哈希挑一套，保证同主题每次一致。"""
    want = str(大纲.get("theme", "")).strip().lower()
    for i, c in enumerate(配色表):
        if want in (c["名"], str(i), c["主"].lower()):
            return c
    key = str(大纲.get("title", "")).encode("utf-8")
    idx = int(hashlib.md5(key).hexdigest(), 16) % len(配色表)
    return 配色表[idx]


def 铺背景(slide, 颜色):
    """整页铺一层底色。python-pptx 没有直接设页背景的接口，用一个铺满的矩形代替。"""
    from pptx.enum.shapes import MSO_SHAPE
    形 = slide.shapes.add_shape(MSO_SHAPE.RECTANGLE, 0, 0, 幻灯片宽, 幻灯片高)
    形.fill.solid()
    形.fill.fore_color.rgb = 取色(颜色)
    形.line.fill.background()
    形.shadow.inherit = False
    # 挪到最底层，否则会盖住后加的文字
    slide.shapes._spTree.remove(形._element)
    slide.shapes._spTree.insert(2, 形._element)
    return 形


def 加文本框(slide, 左, 上, 宽, 高, 文本, 字号, 颜色,
             粗体=False, 对齐=PP_ALIGN.LEFT, 行距=1.25, 锚=MSO_ANCHOR.TOP):
    框 = slide.shapes.add_textbox(左, 上, 宽, 高)
    tf = 框.text_frame
    tf.word_wrap = True
    tf.vertical_anchor = 锚
    # 默认内边距偏大，中文排版显得松散
    tf.margin_left = tf.margin_right = Emu(0)
    tf.margin_top = tf.margin_bottom = Emu(0)

    行 = str(文本).split("\n")
    for i, 一行 in enumerate(行):
        p = tf.paragraphs[0] if i == 0 else tf.add_paragraph()
        p.alignment = 对齐
        p.line_spacing = 行距
        run = p.add_run()
        run.text = 一行
        run.font.size = Pt(字号)
        run.font.bold = 粗体
        run.font.color.rgb = 取色(颜色)
        run.font.name = 中文字体
    return 框


def 画封面(prs, 大纲, 色):
    slide = prs.slides.add_slide(prs.slide_layouts[6])   # 6 = 全空白
    铺背景(slide, 色["浅"])

    # 左侧一条主色竖带，简单但比纯色页有设计感
    from pptx.enum.shapes import MSO_SHAPE
    带 = slide.shapes.add_shape(MSO_SHAPE.RECTANGLE, 0, 0, Inches(0.35), 幻灯片高)
    带.fill.solid()
    带.fill.fore_color.rgb = 取色(色["主"])
    带.line.fill.background()
    带.shadow.inherit = False

    标题 = str(大纲.get("title", "未命名演示"))
    副标 = str(大纲.get("subtitle", ""))

    # 标题字号按长度收缩，长标题不至于撑出页面
    字号 = 44 if len(标题) <= 20 else (36 if len(标题) <= 30 else 28)
    加文本框(slide, Inches(1.1), Inches(2.4), Inches(11.2), Inches(1.8),
             标题, 字号, 色["深"], 粗体=True, 行距=1.15)

    if 副标:
        加文本框(slide, Inches(1.1), Inches(4.3), Inches(11.2), Inches(1.0),
                 副标, 18, 色["灰"], 行距=1.35)

    尾 = str(大纲.get("footer", ""))
    if 尾:
        加文本框(slide, Inches(1.1), Inches(6.5), Inches(11.2), Inches(0.5),
                 尾, 12, 色["灰"])
    return slide


def 画目录(prs, 大纲, 色, 页表):
    """超过 4 页才加目录，页数少的时候目录纯属占位。"""
    if len(页表) < 4:
        return None
    slide = prs.slides.add_slide(prs.slide_layouts[6])
    铺背景(slide, "FFFFFF")
    加文本框(slide, Inches(0.9), Inches(0.7), Inches(11.5), Inches(0.9),
             "目录", 30, 色["深"], 粗体=True)

    from pptx.enum.shapes import MSO_SHAPE
    线 = slide.shapes.add_shape(MSO_SHAPE.RECTANGLE,
                                Inches(0.9), Inches(1.65), Inches(1.2), Emu(38100))
    线.fill.solid()
    线.fill.fore_color.rgb = 取色(色["主"])
    线.line.fill.background()
    线.shadow.inherit = False

    # 超过 10 条就分两列，否则一列排到页底装不下
    条 = [str(p.get("title", "")) for p in 页表][:16]
    两列 = len(条) > 8
    每列 = (len(条) + 1) // 2 if 两列 else len(条)
    for i, 文 in enumerate(条):
        列 = 0 if i < 每列 else 1
        行 = i if i < 每列 else i - 每列
        左 = Inches(1.0) + (Inches(5.9) if 列 else 0)
        上 = Inches(2.1) + Inches(0.52) * 行
        加文本框(slide, 左, 上, Inches(5.4), Inches(0.45),
                 "%02d   %s" % (i + 1, 文), 15, 色["灰"])
    return slide


def 画内容页(prs, 页, 色, 序号, 总数):
    slide = prs.slides.add_slide(prs.slide_layouts[6])
    铺背景(slide, "FFFFFF")

    标题 = str(页.get("title", ""))
    字号 = 28 if len(标题) <= 24 else 22
    加文本框(slide, Inches(0.9), Inches(0.6), Inches(11.5), Inches(0.9),
             标题, 字号, 色["深"], 粗体=True)

    from pptx.enum.shapes import MSO_SHAPE
    线 = slide.shapes.add_shape(MSO_SHAPE.RECTANGLE,
                                Inches(0.9), Inches(1.5), Inches(1.2), Emu(38100))
    线.fill.solid()
    线.fill.fore_color.rgb = 取色(色["主"])
    线.line.fill.background()
    线.shadow.inherit = False

    要点 = [str(x) for x in (页.get("bullets") or []) if str(x).strip()]
    正文 = str(页.get("text", "")).strip()

    上 = Inches(2.0)
    if 要点:
        # 要点多了自动缩字号和行距，宁可小一点也不要溢出页面
        n = len(要点)
        字 = 18 if n <= 5 else (16 if n <= 7 else 14)
        间 = Inches(0.72) if n <= 5 else (Inches(0.6) if n <= 7 else Inches(0.5))
        for i, 条 in enumerate(要点[:10]):
            # 圆点用小方块画，比字符 • 在各系统上更一致
            点 = slide.shapes.add_shape(MSO_SHAPE.OVAL,
                                       Inches(1.0), 上 + 间 * i + Emu(50800),
                                       Inches(0.13), Inches(0.13))
            点.fill.solid()
            点.fill.fore_color.rgb = 取色(色["主"])
            点.line.fill.background()
            点.shadow.inherit = False
            加文本框(slide, Inches(1.35), 上 + 间 * i, Inches(10.9), 间,
                     条, 字, 色["灰"], 行距=1.3)
        上 = 上 + 间 * min(len(要点), 10) + Inches(0.2)

    if 正文:
        加文本框(slide, Inches(1.0), 上, Inches(11.3), Inches(1.6),
                 正文, 14, 色["灰"], 行距=1.45)

    # 页脚页码
    加文本框(slide, Inches(11.6), Inches(6.85), Inches(0.9), Inches(0.35),
             "%d / %d" % (序号, 总数), 11, 色["灰"], 对齐=PP_ALIGN.RIGHT)
    return slide


def 画尾页(prs, 大纲, 色):
    slide = prs.slides.add_slide(prs.slide_layouts[6])
    铺背景(slide, 色["浅"])
    文 = str(大纲.get("ending", "") or "谢谢")
    加文本框(slide, Inches(1.0), Inches(3.1), Inches(11.3), Inches(1.2),
             文, 36, 色["深"], 粗体=True, 对齐=PP_ALIGN.CENTER)
    return slide


def 生成(大纲, 输出路径):
    页表 = 大纲.get("slides") or []
    if not isinstance(页表, list) or not 页表:
        raise ValueError("大纲里没有 slides，或者格式不是数组")

    # 上限拦一道：页数太多生成慢、文件大，而且往往是 AI 跑飞了
    if len(页表) > 40:
        页表 = 页表[:40]

    色 = 挑配色(大纲)
    prs = Presentation()
    prs.slide_width = 幻灯片宽
    prs.slide_height = 幻灯片高

    画封面(prs, 大纲, 色)
    画目录(prs, 大纲, 色, 页表)

    总 = len(页表)
    for i, 页 in enumerate(页表):
        if not isinstance(页, dict):
            页 = {"title": str(页)}
        画内容页(prs, 页, 色, i + 1, 总)

    画尾页(prs, 大纲, 色)
    prs.save(输出路径)
    return len(prs.slides._sldIdLst)


def main():
    if len(sys.argv) < 3:
        print(json.dumps({"ok": False, "error": "参数不足，需要 <大纲json> <输出路径>"},
                         ensure_ascii=False))
        return 2
    try:
        with open(sys.argv[1], "r", encoding="utf-8") as f:
            大纲 = json.load(f)
    except Exception as e:
        print(json.dumps({"ok": False, "error": "大纲 JSON 读取失败：%s" % e},
                         ensure_ascii=False))
        return 3
    try:
        n = 生成(大纲, sys.argv[2])
    except Exception as e:
        print(json.dumps({"ok": False, "error": "生成失败：%s" % e}, ensure_ascii=False))
        return 4

    print(json.dumps({"ok": True, "slides": n}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main())
