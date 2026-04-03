#!/usr/bin/env node
/**
 * 版本号自动管理脚本
 * 功能：
 * 1. 优先从环境变量 GITHUB_REF_NAME (tag) 获取版本号
 * 2. 如果没有 tag，则自动递增 patch 版本号
 * 遵循 SemVer 规范：major.minor.patch
 */

import { readFileSync, writeFileSync } from 'fs';
import { execSync } from 'child_process';
import { fileURLToPath } from 'url';
import { dirname, resolve } from 'path';

// 获取脚本所在目录
const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

// package.json 路径
const packagePath = resolve(__dirname, '../package.json');

/**
 * 从 Git tag 或环境变量获取版本号
 * @returns {string|null} - 版本号或 null
 */
function getVersionFromTag() {
  // 优先从 GitHub Actions 环境变量获取
  const refName = process.env.GITHUB_REF_NAME;
  if (refName && refName.startsWith('v')) {
    return refName.substring(1); // 去掉 'v' 前缀
  }
  
  // 尝试从本地 Git tag 获取
  try {
    const tag = execSync('git describe --tags --abbrev=0 2>/dev/null', { encoding: 'utf-8' }).trim();
    if (tag && tag.startsWith('v')) {
      return tag.substring(1);
    }
  } catch {
    // 没有 tag，忽略错误
  }
  
  return null;
}

/**
 * 递增版本号的 patch 位
 * @param {string} version - 当前版本号 (如 "1.6.80")
 * @returns {string} - 新版本号 (如 "1.6.81")
 */
function bumpPatch(version) {
  const parts = version.split('.');
  if (parts.length !== 3) {
    throw new Error(`版本号格式错误: ${version}，应为 major.minor.patch 格式`);
  }
  
  const [major, minor, patch] = parts;
  const newPatch = parseInt(patch, 10) + 1;
  
  if (isNaN(newPatch)) {
    throw new Error(`无法解析 patch 版本号: ${patch}`);
  }
  
  return `${major}.${minor}.${newPatch}`;
}

/**
 * 主函数：读取 package.json，更新版本号，写回文件
 */
function main() {
  try {
    // 读取 package.json
    const content = readFileSync(packagePath, 'utf-8');
    const pkg = JSON.parse(content);
    
    // 获取当前版本
    const oldVersion = pkg.version;
    if (!oldVersion) {
      throw new Error('package.json 中未找到 version 字段');
    }
    
    // 尝试从 tag 获取版本号
    let newVersion = getVersionFromTag();
    let source = 'tag';
    
    // 如果没有 tag，则自动递增
    if (!newVersion) {
      newVersion = bumpPatch(oldVersion);
      source = 'auto-increment';
    }
    
    // 如果版本号相同，跳过更新
    if (newVersion === oldVersion) {
      console.log(`\n✅ Build Started: Version ${oldVersion} (unchanged)\n`);
      process.exit(0);
    }
    
    // 更新版本号
    pkg.version = newVersion;
    
    // 写回 package.json（保持 2 空格缩进）
    writeFileSync(packagePath, JSON.stringify(pkg, null, 2) + '\n', 'utf-8');
    
    // 打印版本变更信息
    console.log(`\n🚀 Build Started: Version updated from ${oldVersion} to ${newVersion} (source: ${source})\n`);
    
    // 返回成功
    process.exit(0);
  } catch (error) {
    // 错误处理：打印错误并中断构建
    console.error(`\n❌ 版本号更新失败: ${error.message}\n`);
    process.exit(1);
  }
}

// 执行主函数
main();
