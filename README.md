# SmartExpiry

SmartExpiry 为 Xboard 后台的用户表单添加安全、便捷的按自然月调整到期时间功能。

## 支持场景

1. 编辑用户
2. 创建用户

两个场景均提供“永久”以及增加 1、3、6、9、12 个月的快捷操作。移动端使用稳定的三列按钮布局。创建用户页面还会复用编辑用户页面的日期时间控件，包括日历以及浏览器原生的时间选择器，并支持时、分、秒精度。

## 安装方法

本仓库发布的 ZIP 是完整插件安装包，不是增量补丁。它既可以安装到从未安装过 SmartExpiry 的纯净 Xboard，也可以覆盖旧版进行升级；首次安装不需要预先安装 v1.0、v1.1.1 或任何其他版本。

### 前置步骤：设置后台静态资源权限

SmartExpiry 在安装期间需要更新 Xboard 已编译的后台静态资源。上传插件前，请确保 PHP-FPM 运行用户对后台静态资源目录拥有写入权限。以下示例使用常见的 `www:www` 用户和用户组；如果你的 PHP-FPM 使用其他账户，请按实际情况替换。

```bash
cd /path/to/xboard

sudo chown -R www:www public/assets/admin
sudo find public/assets/admin -type d -exec chmod 755 {} +
sudo find public/assets/admin -type f -exec chmod 644 {} +
```

安装前可使用以下命令验证写入权限：

```bash
sudo -u www test -w public/assets/admin/locales/en-US.js \
  && echo "Writable" \
  || echo "Not writable"
```

请勿使用 `chmod -R 777`。如果 PHP-FPM 运行用户不是 `www`，可执行 `ps aux | grep '[p]hp-fpm'` 查看实际运行账户。

1. 从 GitHub Release 下载 `SmartExpiry-1.1.4-full.zip`。
2. 在 Xboard 插件管理页面上传安装包。
3. 安装并启用 `smart_expiry`。如果已安装旧版本，直接上传此版本会进入 Xboard 的常规插件更新流程。

PHP 进程必须能够写入 `public/assets/admin` 下当前正在使用的文件。建议安装前创建备份或文件系统快照。由于当前 Xboard 未提供前端插件钩子，SmartExpiry 需要对已编译的后台资源进行桥接修改。

## 使用说明

创建用户页面中的“永久”仅用于替换 Xboard 原有永久操作的界面文案，写入值仍为 `null`，不会生成替代时间戳，也不会引入新的持久化规则。

每个到期时间快捷按钮都会读取表单中最新的 `expired_at` 值，并统一按照以下规则计算：

```text
valid(currentExpiry) && currentExpiry > now
    ? addMonths(currentExpiry, months)
    : addMonths(now, months)
```

即：当前到期时间有效且晚于现在时，从当前到期时间继续增加月份；否则从现在开始增加月份。

按自然月计算时会自动处理月末日期，并保留小时、分钟和秒。快捷控件只更新表单状态，用户仍需点击原有的“保存”或“创建”按钮提交。

## 兼容与安全机制

桥接程序会从后台 `index.html` 自动解析当前生效的入口文件，不再检查 Xboard 的 Git 提交版本或 SHA-256 哈希。为避免错误修改，写入前仍要求所有结构锚点准确且唯一。

程序通过标记识别未修改、SmartExpiry v1 至 SmartExpiry v5 以及部分修改等状态；检测到部分修改时会拒绝继续处理。如果多文件写入过程中发生失败，本次操作中已经改动的文件会自动恢复。

插件会为后台入口脚本和语言文件添加版本查询参数，以避免浏览器继续使用旧缓存。新增文案会执行显式键名检测并提供中、英、俄三种回退，即使语言资源不可用，也不会显示 `edit.form...` 或 `generate.form...` 翻译键。v1.1.4 修复了 v1.1.3 在编辑用户页面触发的 `e is not a function` 错误，并可直接修复卸载插件后仍然残留的 v4 后台资源。
