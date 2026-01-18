const sharp = require('sharp');
const path = require('path');
const fs = require('fs');

const iconsDir = path.join(__dirname, '../src-tauri/icons');

// 创建一个简洁的同步图标 - 两个圆形箭头
async function generateIcon() {
  const sizes = [32, 128, 256, 512];
  
  // SVG 图标 - 同步箭头设计
  const svg = `
    <svg width="512" height="512" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg">
      <defs>
        <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
          <stop offset="0%" style="stop-color:#3B82F6"/>
          <stop offset="100%" style="stop-color:#1D4ED8"/>
        </linearGradient>
      </defs>
      <!-- 圆角背景 -->
      <rect x="0" y="0" width="512" height="512" rx="100" fill="url(#bg)"/>
      <!-- 同步箭头 - 上 -->
      <path d="M256 120 L320 180 L290 180 L290 260 L222 260 L222 180 L192 180 Z" 
            fill="white" opacity="0.95"/>
      <!-- 同步箭头 - 下 -->
      <path d="M256 392 L192 332 L222 332 L222 252 L290 252 L290 332 L320 332 Z" 
            fill="white" opacity="0.95"/>
      <!-- 文件图标 - 左 -->
      <rect x="100" y="200" width="80" height="112" rx="8" fill="white" opacity="0.3"/>
      <rect x="108" y="220" width="50" height="6" rx="3" fill="#1D4ED8"/>
      <rect x="108" y="235" width="64" height="6" rx="3" fill="#1D4ED8"/>
      <rect x="108" y="250" width="40" height="6" rx="3" fill="#1D4ED8"/>
      <!-- 文件图标 - 右 -->
      <rect x="332" y="200" width="80" height="112" rx="8" fill="white" opacity="0.3"/>
      <rect x="340" y="220" width="50" height="6" rx="3" fill="#1D4ED8"/>
      <rect x="340" y="235" width="64" height="6" rx="3" fill="#1D4ED8"/>
      <rect x="340" y="250" width="40" height="6" rx="3" fill="#1D4ED8"/>
    </svg>
  `;

  // 生成各尺寸 PNG
  for (const size of sizes) {
    const outputPath = size === 32 
      ? path.join(iconsDir, '32x32.png')
      : size === 128 
        ? path.join(iconsDir, '128x128.png')
        : size === 256
          ? path.join(iconsDir, '128x128@2x.png')
          : path.join(iconsDir, 'icon.png');
    
    await sharp(Buffer.from(svg))
      .resize(size, size)
      .png()
      .toFile(outputPath);
    
    console.log(`Generated: ${outputPath} (${size}x${size})`);
  }

  // 生成 ICO 文件 (使用 32x32 PNG)
  const png32 = await sharp(Buffer.from(svg)).resize(256, 256).png().toBuffer();
  
  // 简单的 ICO 生成 (包含 256x256 PNG)
  const icoPath = path.join(iconsDir, 'icon.ico');
  
  // ICO header
  const header = Buffer.alloc(6);
  header.writeUInt16LE(0, 0); // Reserved
  header.writeUInt16LE(1, 2); // Type (1 = ICO)
  header.writeUInt16LE(1, 4); // Number of images
  
  // ICO directory entry
  const entry = Buffer.alloc(16);
  entry.writeUInt8(0, 0);   // Width (0 = 256)
  entry.writeUInt8(0, 1);   // Height (0 = 256)
  entry.writeUInt8(0, 2);   // Color palette
  entry.writeUInt8(0, 3);   // Reserved
  entry.writeUInt16LE(1, 4); // Color planes
  entry.writeUInt16LE(32, 6); // Bits per pixel
  entry.writeUInt32LE(png32.length, 8); // Image size
  entry.writeUInt32LE(22, 12); // Image offset
  
  const ico = Buffer.concat([header, entry, png32]);
  fs.writeFileSync(icoPath, ico);
  console.log(`Generated: ${icoPath}`);
  
  console.log('All icons generated successfully!');
}

generateIcon().catch(console.error);
