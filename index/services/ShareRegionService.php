<?php
/**
 * 分享区域链接服务
 * 根据配置的节点生成多区域分享链接
 */
require_once __DIR__ . '/../core/db.php';

class ShareRegionService
{
    /**
     * 获取所有启用的区域节点
     * @return array
     */
    public static function getActiveRegions(): array
    {
        return Db::query(
            "SELECT * FROM share_regions WHERE status = 1 ORDER BY sort_order ASC, id ASC"
        ) ?: [];
    }

    /**
     * 根据token生成所有区域的分享链接
     * @param string $token 分享token
     * @param string $path 路径，默认 /share/
     * @return array [['region_name' => '...', 'url' => '...', 'is_default' => 0/1], ...]
     */
    public static function generateRegionUrls(string $token, string $path = '/file_share.php?token='): array
    {
        $regions = self::getActiveRegions();
        $urls = [];

        foreach ($regions as $region) {
            $url = self::buildUrl($region, $path . $token);
            $urls[] = [
                'id' => (int)$region['id'],
                'region_name' => $region['region_name'],
                'url' => $url,
                'is_default' => (int)$region['is_default'],
                'domain' => $region['domain'],
                'port' => $region['port'],
                'protocol' => $region['protocol'],
            ];
        }

        return $urls;
    }

    /**
     * 构建完整URL
     * @param array $region 区域配置
     * @param string $path 路径（包含token）
     * @return string
     */
    public static function buildUrl(array $region, string $path): string
    {
        $url = $region['protocol'] . '://' . $region['domain'];
        if (!empty($region['port'])) {
            $url .= ':' . $region['port'];
        }
        $url .= $path;
        return $url;
    }

    /**
     * 获取默认区域的URL
     * @param string $token
     * @param string $path
     * @return string|null
     */
    public static function getDefaultUrl(string $token, string $path = '/file_share.php?token='): ?string
    {
        $regions = self::getActiveRegions();
        foreach ($regions as $region) {
            if ($region['is_default']) {
                return self::buildUrl($region, $path . $token);
            }
        }
        // 如果没有默认，返回第一个
        if (!empty($regions)) {
            return self::buildUrl($regions[0], $path . $token);
        }
        return null;
    }
}
