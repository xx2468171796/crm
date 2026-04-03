# CRM 项目 Git 使用指南

本文档介绍如何在新电脑上克隆项目、配置开发环境以及多人协作流程。

---

## 一、首次使用（新电脑配置）

### 1. 安装 Git

**Windows:**
- 下载：https://git-scm.com/download/win
- 安装时选择默认选项即可

**Mac:**
```bash
brew install git
# 或者安装 Xcode Command Line Tools
xcode-select --install
```

**Linux (Ubuntu/Debian):**
```bash
sudo apt update && sudo apt install git
```

### 2. 配置 Git 用户信息

```bash
git config --global user.name "你的用户名"
git config --global user.email "你的邮箱@example.com"
```

### 3. 生成 SSH 密钥（推荐）

```bash
# 生成密钥（一路回车即可）
ssh-keygen -t ed25519 -C "你的邮箱@example.com"

# 查看公钥
cat ~/.ssh/id_ed25519.pub
```

将输出的公钥添加到 GitHub：
1. 打开 https://github.com/settings/keys
2. 点击 "New SSH key"
3. 粘贴公钥并保存

### 4. 测试 SSH 连接

```bash
ssh -T git@github.com
```

看到 `Hi xxx! You've successfully authenticated` 表示成功。

---

## 二、克隆项目

### 方式一：SSH（推荐）

```bash
git clone git@github.com:xx2468171796/crm.git
cd crm
```

### 方式二：HTTPS

```bash
git clone https://github.com/xx2468171796/crm.git
cd crm
```

---

## 三、安装项目依赖

### 1. PHP 后端依赖

```bash
cd index
composer install
```

### 2. 桌面端依赖（如需开发桌面应用）

```bash
cd index/desktop
npm install
```

### 3. 配置文件

复制示例配置并修改：

```bash
cd index
cp config_example.php config.php
cp .env.example .env
```

编辑 `config.php` 填入数据库等配置信息。

---

## 四、日常开发流程

### 1. 开始工作前 - 拉取最新代码

```bash
git pull origin master
```

### 2. 查看当前状态

```bash
git status
```

### 3. 提交修改

```bash
# 添加所有修改
git add -A

# 或添加指定文件
git add 文件路径

# 提交（写清楚做了什么）
git commit -m "feat: 添加xxx功能"
```

### 4. 推送到远程

```bash
git push origin master
```

---

## 五、多人协作（分支工作流）

### 推荐流程

```
master (主分支) ─────────────────────────────────►
         │                        │
         └── feature/xxx ────────►│ (合并)
                                  │
         └── feature/yyy ────────►│ (合并)
```

### 1. 创建功能分支

```bash
# 从 master 创建新分支
git checkout -b feature/功能名称

# 例如
git checkout -b feature/user-management
```

### 2. 在分支上开发

```bash
# 正常开发、提交
git add -A
git commit -m "feat: 完成用户管理模块"

# 推送分支到远程
git push origin feature/功能名称
```

### 3. 合并到主分支

```bash
# 切换回 master
git checkout master

# 拉取最新代码
git pull origin master

# 合并功能分支
git merge feature/功能名称

# 推送
git push origin master

# 删除本地分支（可选）
git branch -d feature/功能名称
```

### 4. 处理冲突

如果合并时出现冲突：

```bash
# 查看冲突文件
git status

# 手动编辑冲突文件，解决冲突后：
git add -A
git commit -m "resolve: 解决合并冲突"
```

---

## 六、常用命令速查

| 命令 | 说明 |
|------|------|
| `git status` | 查看当前状态 |
| `git pull` | 拉取远程更新 |
| `git add -A` | 添加所有修改 |
| `git commit -m "消息"` | 提交 |
| `git push` | 推送到远程 |
| `git log --oneline -10` | 查看最近10条提交 |
| `git diff` | 查看未暂存的修改 |
| `git checkout -- 文件` | 撤销文件修改 |
| `git reset HEAD~1` | 撤销最近一次提交（保留修改） |
| `git branch` | 查看本地分支 |
| `git branch -a` | 查看所有分支 |
| `git checkout 分支名` | 切换分支 |
| `git stash` | 暂存当前修改 |
| `git stash pop` | 恢复暂存的修改 |

---

## 七、提交信息规范

建议使用以下前缀：

| 前缀 | 说明 | 示例 |
|------|------|------|
| `feat:` | 新功能 | `feat: 添加客户导出功能` |
| `fix:` | 修复bug | `fix: 修复登录失败问题` |
| `docs:` | 文档修改 | `docs: 更新API文档` |
| `style:` | 代码格式 | `style: 格式化代码` |
| `refactor:` | 重构 | `refactor: 重构用户模块` |
| `perf:` | 性能优化 | `perf: 优化查询速度` |
| `chore:` | 其他修改 | `chore: 更新依赖版本` |

---

## 八、VSCode 集成

### 推荐插件

1. **GitLens** - 增强 Git 功能，查看代码历史
2. **Git Graph** - 可视化分支图

### 使用 VSCode 操作 Git

1. 左侧边栏点击 "源代码管理" 图标
2. 可以直接在界面上：
   - 查看修改的文件
   - 暂存文件（点击 +）
   - 提交（输入消息后点击 ✓）
   - 推送/拉取（点击 ... 菜单）

---

## 九、常见问题

### Q: 推送被拒绝怎么办？

```bash
# 先拉取远程更新
git pull origin master

# 解决冲突后再推送
git push origin master
```

### Q: 误删了文件怎么恢复？

```bash
# 恢复单个文件
git checkout -- 文件路径

# 恢复所有文件
git checkout -- .
```

### Q: 想撤销最近的提交？

```bash
# 撤销提交但保留修改
git reset --soft HEAD~1

# 撤销提交并丢弃修改
git reset --hard HEAD~1
```

### Q: 如何查看某个文件的修改历史？

```bash
git log --follow -p 文件路径
```

---

## 十、项目仓库信息

- **仓库地址**: https://github.com/xx2468171796/crm
- **SSH 地址**: git@github.com:xx2468171796/crm.git
- **主分支**: master

---

*最后更新: 2026-01-18*
