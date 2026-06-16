# stoneChat "XP 时代风格" 重梳设计

日期：2026-06-16
范围：`.php` 与 `.htm`（含 `Pages/css/main.css` 的注释块）；少数例外见下。

## 1. 目标

把现有代码风格与注释风格改造为 2001–2007 年（Windows XP / PHP 5.2 时代）的观感，让 `SuperModern` 部分（见 §3）的"现代感"在对比下更突出。

整体思路：**注释大幅简化、结构保持**。不重写算法、不破坏 `sc_` 公共接口、不引入新的依赖。

## 2. 风格模板（目标态示例）

### 2.1 文件头注释

改造前：

```php
<?php
/**
 * stoneChat Server config loader and validator.
 *
 * Public functions (all sc_-prefixed, each wrapped in a function_exists guard):
 *   sc_load_config($ini_path)
 *       Parse CONF.ini into a nested array; empty array on any failure.
 *   sc_load_providers($ini_path)
 *       Extract all [Provider N] sections as a normalized list
 *       (id/label/type/api_base/api_key/model), ordered by N.
 *   sc_validate_config($cfg)
 *       Check a parsed config for required keys and provider integrity.
 *       Returns an array of short error codes (empty array on success).
 *
 * Compatible with PHP 5.2 (no closures, no [] array syntax, no namespaces).
 */
```

改造后（极简 C 风格）：

```php
<?php
/* -------------------------------------------------------------------------
 * stoneChat / Server/config.php
 *
 * Load and validate CONF.ini. Public helpers (sc_-prefixed, all guarded
 * with function_exists for include-twice safety):
 *
 *   sc_load_config($path)            parse CONF.ini, empty array on failure
 *   sc_load_providers($path)         [Provider N] -> normalized list
 *   sc_validate_config($cfg)         check keys; returns error code list
 *   sc_validate_path_resolve(...)    resolve a relative path under base
 *   sc_is_placeholder_password(...)  is the password an unfilled stub?
 *
 * PHP 5.2 compatible.
 * ------------------------------------------------------------------------- */
```

要点：
- 顶部分隔线 73 字符宽（取自 80 列减去 4 列缩进的常用宽度）
- 用 `/* --- */` 一行总标题 + 简短列表
- 不再用 PHPDoc 的 `@param/@return` 块

### 2.2 函数头注释

改造前：

```php
/**
 * Load the stoneChat INI config file as a nested array.
 *
 * @param string $ini_path Absolute or relative path to CONF.ini.
 * @return array Parsed config, or empty array on any failure.
 */
function sc_load_config($ini_path) {
```

改造后：

```php
/* sc_load_config($ini_path)
 *   Parse CONF.ini into a nested array; empty array on failure. */
function sc_load_config($ini_path) {
```

要点：
- 函数签名放在注释第一行，方便用 grep `^function`
- 描述一行结束；若说明超过 60 字符则继续；绝不超过 70 字符宽
- 完全删除 `@param/@return` 标签

### 2.3 文件内分段

改造前：

```php
// =============================================================
// Pre-flight guards (run before any helper is used; both exit on
// failure so they MUST sit above the function definitions).
// =============================================================
```

改造后：

```php
/* ---- pre-flight guards ------------------------------------------- */
```

要点：
- 用单行 `/* ---- 标题 ----- */` 风格（与 2.2 函数头一致），不再用 60 字符宽的双线
- 标题左对齐，剩余宽度用 `-` 填到 73 列

### 2.4 行内 / 段内注释

- 删除解释 *为什么* 这么写的长段落（这些是 2010 年代后期才普及的风格）
- 保留解释 *做什么* 的短注释（≤ 60 字符一行），写在被解释代码**上方**
- 不再用 `// FIXME` / `// TODO` 外的英文长注释；遗留的英文长注释改为一句中文或一句英文短句
- `// ---- section ----` 这种块内小分隔保留

### 2.5 PHP 4 化处理（保持 5.2 兼容）

按用户选择"注释 + PHP 代码 PHP 4 化"：

1. **删除 PHPDoc 块** —— 替换为 2.2 的简短函数头
2. **`@param/@return` 删除** —— 改用普通短句
3. **行宽收紧** —— 目标 ≤ 80 列（当前部分行已接近 100）
4. **函数按"功能相近"重排** —— 旧 PHP 项目的常见做法是：先 helper、后 main。当前文件已经是这个顺序，保持即可
5. **常量命名** —— 已有 `SC_*` 类名常量风格，保持不动
6. **`static` 变量** —— 已存在的 `static $cached` 等保持不动（PHP 4 就支持）
7. **`call_user_func`/`create_function` 等** —— 已无使用，保持
8. **命名风格** —— `snake_case` 已统一，保持
9. **错误处理** —— 不强制改为 `@` 抑制 + `die()`，因为已有结构是 5.2 友好的，仅在过度啰嗦处精简

### 2.6 HTML（`.htm`）风格

- `<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" ...>` —— 已是 XP 时代风格，**保持**
- 顶部 PHP/XML 声明 `<?xml version="1.0" encoding="UTF-8"?>` —— 保持
- HTML 内嵌 `<style>` / `<script>` 块：把文件头那段长 CSS/JS 注释改为 2.1 同款 `/* ----- ... ----- */` 风格
- 表单元素 `on*=` 内联事件保持（XP 时代普遍写法）

### 2.7 CSS / JS 注释

- `Pages/css/main.css` 顶部大段说明性注释：精简为一段 `/* ----- ... ----- */` 头
- `Pages/js/*.js` 中各文件的开头大 PHPDoc 风格注释：改为 `/* ----- file purpose ----- */` 风格
- 函数/对象的方法头：若原为 PHPDoc `@param/@return`，改为 2.2 同款短头

## 3. 例外（保持现代风格）

完全不动的文件：

- `Pages/super-modern.htm`
- `Pages/js/modern-banner.js`

这两者刻意保留现代特性（CSS3 动画、WebGL、`<canvas>`、现代 cookie 属性等），与 §2 形成鲜明对比。

## 4. 改动清单

待改文件清单（按目录分组，共 25 个 `.php` + 3 个 `.htm` + 1 个 `.css` + 5 个 `.js`）：

### Server/

- `Server/boot_check.php`
- `Server/config.php`
- `Server/install.php`
- `Server/llm.php`
- `Server/auth.php`
- `Server/history.php`
- `Server/i18n.php`
- `Server/hello.php`
- `Server/api/auth.php`
- `Server/api/chat.php`
- `Server/api/config.php`
- `Server/api/history.php`
- `Server/api/lang.php`
- `Server/api/mock_llm.php`
- `Server/api/providers.php`
- `Server/langs/de.php`
- `Server/langs/en.php`
- `Server/langs/fr.php`
- `Server/langs/ja.php`
- `Server/langs/ko.php`
- `Server/langs/ru.php`
- `Server/langs/zh-CN.php`
- `Server/langs/zh-TW.php`

### Pages/

- `Pages/index.php`
- `Pages/router.php`
- `Pages/index.htm`
- `Pages/chat.htm`
- `Pages/css/main.css`
- `Pages/js/app.js`
- `Pages/js/api.js`
- `Pages/js/chat.js`
- `Pages/js/i18n.js`

### ModernNetwork/（不在"SuperModern 例外"内）

- `ModernNetwork/proxy.php`

### 不动

- `Pages/super-modern.htm`
- `Pages/js/modern-banner.js`
- 所有非脚本文件：`README`、`CONF.ini`、`HISTORY`、`INSTALL.bat`、`RUN.bat`、`LICENSE.txt`、`.gitignore`、二进制资源（`Assets/`、`HISTORY/`）

注：`Pages/index.php` 本身要按 XP 风格改注释与 PHPDoc 块，但**不改其路由/转发逻辑**。

## 5. 验证方式

1. **语法检查**：本地 PHP（任意版本）`php -l <file>` 应通过
2. **字面检查**：在每个改后文件里 grep `\* @param`、`\* @return`、`/\*\*` 应均无结果
3. **宽度抽查**：随机抽 5 个文件，确认最长行 ≤ 80 字符
4. **行为不变**：不改算法，结构与逻辑保持；测试方式以"grep 函数签名仍能找到"为准
5. **不动例外**：`super-modern.htm` 与 `modern-banner.js` 应**完全不变**（用 `git diff` 验证空）

## 6. 风险与限制

- **风险低**：纯风格改造，不动控制流与 API
- **限制**：长函数体（如 `sc_api_chat_handle_send_stream`）的内部注释可能仍较长，按"长则保留一行概要"原则处理
- **不改的语言文件**：`Server/langs/*.php` 仅改文件头/函数头，**不改任何键值对**（这些是 i18n 数据）
- **不改 `Pages/router.php` 的 URL 路由逻辑**：只改注释

## 7. 执行顺序

1. Server/ 下基础设施（boot_check, config, auth, history, llm）先行
2. Server/api/ 接着
3. Server/langs/ 与 Pages/ 最后
4. 全部完成后跑 `php -l` 自检
