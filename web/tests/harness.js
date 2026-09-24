/**
 * 卡片脚本的测试加载器。
 *
 * 卡片脚本是浏览器 IIFE，直接 require 会因为没有 window/document 而崩。
 * 这里用 vm 造一个最小沙箱：只补脚本加载时真正会碰的全局，
 * 不引 jsdom，省一个依赖，也省得为了测解析逻辑去模拟整个 DOM。
 *
 * 沙箱里的 fetch 默认不发请求，返回可控结果，测试要断言请求参数时自己换掉。
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const JS目录 = path.join(__dirname, '..', 'assets', 'js');
/** 造一个够用的 document 桩：卡片脚本加载时会调 addEventListener 和 createElement */
function 造document() {
  const 元素 = () => ({
    className: '', innerHTML: '', textContent: '', style: {}, hidden: false,
    children: [], attrs: {},
    setAttribute(k, v) { this.attrs[k] = String(v); },
    getAttribute(k) { return Object.prototype.hasOwnProperty.call(this.attrs, k) ? this.attrs[k] : null; },
    appendChild(c) { this.children.push(c); return c; },
    querySelector() { return null; },
    querySelectorAll() { return []; },
    addEventListener() {},
    closest() { return null; },
    remove() {}
  });
  return {
    监听: {},
    createElement: 元素,
    createTextNode: (t) => ({ textContent: String(t) }),
    getElementById: () => null,
    querySelector: () => null,
    querySelectorAll: () => [],
    addEventListener(名, fn) { this.监听[名] = fn; },
    body: 元素()
  };
}
/**
 * 加载一个卡片脚本，返回沙箱里的 window。
 * 选项 fetch 可传自定义实现；不传时任何请求都会让测试失败，
 * 避免测解析的用例意外打真接口。
 */
function 装载(文件名, 选项) {
  选项 = 选项 || {};
  const 源 = fs.readFileSync(path.join(JS目录, 文件名), 'utf8');
  const doc = 造document();
  const win = {
    CSRF: 'test-csrf-token',
    currentConvId: 12345,
    FormData: class {
      constructor() { this.项 = []; }
      append(k, v) { this.项.push([k, String(v)]); }
      get(k) { const f = this.项.find((p) => p[0] === k); return f ? f[1] : null; }
    },
    fetch: 选项.fetch || (() => { throw new Error('本用例不该发请求'); }),
    setTimeout, clearTimeout, console
  };
  win.window = win;
  win.document = doc;
  win.self = win;
  const 沙箱 = vm.createContext(win);
  vm.runInContext(源, 沙箱, { filename: 文件名 });
  return win;
}
module.exports = { 装载, JS目录 };
