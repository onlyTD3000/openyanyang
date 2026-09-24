-- ========== 记住我 / 自动登录 ==========
-- 需求是「勾选后记住密码自动登录，七天内免登录」。
-- 密码不落任何地方：真存明文密码（哪怕加密存）都等于把账号交给能碰到那台电脑的人。
-- 改成发一张长效令牌，效果一样是七天免登录，但令牌泄漏顶多丢一个会话，
-- 不会连带丢密码，而且能单独吊销。
--
-- 令牌拆成 selector + validator 两段：
--   selector  随机串，明文存库并建唯一索引，只用来把行查出来
--   validator 随机串，库里只存 sha256，比对时用 hash_equals 定长比较
-- 为什么不能只用一个串：那样要么拿明文当查询条件（库被读到就能直接冒充），
-- 要么拿哈希当查询条件（等于用哈希做等值匹配，没法做定长比较，留时序侧信道）。
--
-- 每次自动登录成功就换一张新的（轮换），旧的立刻失效。这样即使令牌被复制走，
-- 真实用户下次访问会把它顶掉，攻击者手里那张就用不了了。
CREATE TABLE IF NOT EXISTS auth_tokens (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  -- 明文存，仅用于定位行；本身不构成凭据
  selector   CHAR(32) NOT NULL,
  -- validator 的 sha256，64 位十六进制
  validator  CHAR(64) NOT NULL,
  -- 签发时的环境，供用户在个人资料里辨认「这是哪台设备」
  ua         VARCHAR(200) NOT NULL DEFAULT '',
  ip         VARCHAR(45) NOT NULL DEFAULT '',
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  -- 最后一次靠这张令牌自动登录的时间
  used_at    DATETIME NULL,
  PRIMARY KEY (id),
  -- 查询入口，必须唯一：selector 撞了会导致取错行
  UNIQUE KEY uk_selector (selector),
  -- 退出登录时按 user_id 批量删
  KEY idx_user (user_id),
  -- 清理过期令牌
  KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
