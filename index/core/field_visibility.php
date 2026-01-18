<?php
/**
 * 字段可见性过滤服务
 * 根据配置过滤客户/项目字段
 */

require_once __DIR__ . '/db.php';

class FieldVisibilityService {
    private static $cache = [];
    
    /**
     * 获取实体类型的可见字段配置
     * @param string $entityType customer|project
     * @param string $viewerType internal|tech|client
     * @return array 可见字段列表
     */
    public static function getVisibleFields($entityType, $viewerType = 'internal') {
        $cacheKey = "{$entityType}_{$viewerType}";
        
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }
        
        $pdo = Db::pdo();
        $sql = "SELECT field_key, field_label FROM field_visibility_config WHERE entity_type = ?";
        $params = [$entityType];
        
        if ($viewerType === 'tech') {
            $sql .= " AND tech_visible = 1";
        } elseif ($viewerType === 'client') {
            $sql .= " AND client_visible = 1";
        }
        
        $sql .= " ORDER BY sort_order";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        self::$cache[$cacheKey] = $fields;
        return $fields;
    }
    
    /**
     * 过滤数据，只保留可见字段
     * @param array $data 原始数据
     * @param string $entityType customer|project
     * @param string $viewerType internal|tech|client
     * @return array 过滤后的数据
     */
    public static function filterData($data, $entityType, $viewerType = 'internal') {
        if ($viewerType === 'internal') {
            return $data; // 内部用户看全部
        }
        
        $visibleFields = self::getVisibleFields($entityType, $viewerType);
        $visibleKeys = array_column($visibleFields, 'field_key');
        
        // 基础字段始终可见
        $alwaysVisible = ['id', 'create_time', 'update_time'];
        $visibleKeys = array_merge($visibleKeys, $alwaysVisible);
        
        $filtered = [];
        foreach ($data as $key => $value) {
            if (in_array($key, $visibleKeys)) {
                $filtered[$key] = $value;
            }
        }
        
        return $filtered;
    }
    
    /**
     * 批量过滤数据列表
     * @param array $dataList 原始数据列表
     * @param string $entityType customer|project
     * @param string $viewerType internal|tech|client
     * @return array 过滤后的数据列表
     */
    public static function filterDataList($dataList, $entityType, $viewerType = 'internal') {
        if ($viewerType === 'internal') {
            return $dataList;
        }
        
        return array_map(function($item) use ($entityType, $viewerType) {
            return self::filterData($item, $entityType, $viewerType);
        }, $dataList);
    }
    
    /**
     * 清除缓存
     */
    public static function clearCache() {
        self::$cache = [];
    }
}
