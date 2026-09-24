<?php
/** 服务器登记弹窗与结果弹窗。由 servers.php 引入。 */
?>
<div class="modal" id="hostModal" hidden>
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="hostModalTitle">
    <div class="modal-head">
      <h3 id="hostModalTitle">登记服务器</h3>
      <button class="modal-x" type="button" data-close aria-label="关闭">×</button>
    </div>
    <form id="hostForm" class="modal-body">
      <input type="hidden" name="id" value="0">
      <div class="fld">
        <label for="f_name">备注名 <span class="req">*</span></label>
        <input id="f_name" name="name" required maxlength="40" placeholder="如：我的测试机">
      </div>
      <div class="fld-row">
        <div class="fld">
          <label for="f_host">主机地址 <span class="req">*</span></label>
          <input id="f_host" name="host" required placeholder="域名或公网 IP">
        </div>
        <div class="fld fld-narrow">
          <label for="f_port">端口</label>
          <input id="f_port" name="port" type="number" value="22" min="1" max="65535">
        </div>
      </div>
      <div class="fld">
        <label for="f_user">登录用户 <span class="req">*</span></label>
        <input id="f_user" name="username" required placeholder="建议用权限受限的专用账号，不要用 root">
      </div>
      <div class="fld">
        <label for="f_auth">认证方式</label>
        <select id="f_auth" name="auth_type">
          <option value="key">SSH 私钥（推荐）</option>
          <option value="password">密码</option>
        </select>
      </div>
      <div class="fld" id="wrapSecret">
        <label for="f_secret">
          <span id="labSecret">私钥内容</span>
          <span class="req" id="reqSecret">*</span>
        </label>
        <textarea id="f_secret" name="secret" rows="6"
                  placeholder="粘贴私钥全文，含 -----BEGIN ... KEY----- 与结尾行"></textarea>
        <div class="fld-hint" id="hintSecret">
          编辑时留空表示不修改。目前密钥登录支持 RSA 格式，
          可用 <code>ssh-keygen -t rsa -b 2048 -m PEM</code> 生成。
        </div>
      </div>
      <div class="fld" id="wrapKpass">
        <label for="f_kpass">私钥口令</label>
        <input id="f_kpass" name="key_pass" type="password" placeholder="私钥没有口令就留空" autocomplete="new-password">
      </div>
      <div class="modal-err" id="hostErr" hidden role="alert"></div>
      <div class="modal-foot">
        <button class="btn" type="button" data-close>取消</button>
        <button class="btn btn-primary" type="submit" id="btnSave">保存</button>
      </div>
    </form>
  </div>
</div>

<div class="modal" id="outModal" hidden>
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="outModalTitle">
    <div class="modal-head">
      <h3 id="outModalTitle">执行结果</h3>
      <button class="modal-x" type="button" data-close aria-label="关闭">×</button>
    </div>
    <div class="modal-body">
      <pre class="term" id="outText" tabindex="0"></pre>
      <div class="modal-foot">
        <button class="btn btn-primary" type="button" data-close>关闭</button>
      </div>
    </div>
  </div>
</div>
