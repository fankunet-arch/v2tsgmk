<?php
/**
 * KDS SOP Engine - business rules & code parsing
 * Auto-extracted from kds_helper.php (Phase 1).
 * [GEMINI FIX V2] Restored KdsSopParser class definition.
 */

if (!class_exists('KdsSopParser')) {
    class KdsSopParser {
        private $pdo;
        private $store_id;
        private $rules = null; // 缓存规则

        public function __construct(PDO $pdo, int $store_id) {
            $this->pdo = $pdo;
            $this->store_id = $store_id;
        }

        /**
         * 加载此门店可用的所有SOP解析规则
         */
        private function loadRules(): void {
            if ($this->rules !== null) return;

            // 智能 SQL：优先拉取门店专属规则 (store_id DESC)，然后按优先级 (priority ASC)
            $sql = "
                SELECT * FROM kds_sop_query_rules
                WHERE is_active = 1
                  AND (store_id = :current_store_id OR store_id IS NULL)
                ORDER BY 
                  store_id DESC,  /* 确保门店专属规则 (e.g., 1) 排在全局规则 (NULL) 之前 */
                  priority ASC
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':current_store_id' => $this->store_id]);
            $this->rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        /**
         * 解析 "分隔符" 模式 (e.g., P-A-M-T)
         */
        private function _parseDelimiter(string $code, array $config): ?array {
            $format_str = $config['format'] ?? '';     // "P-A-M-T"
            $separator = $config['separator'] ?? '-'; // "-"
            $prefix = $config['prefix'] ?? '';       // "#"

            // 1. 检查前缀
            if (!empty($prefix)) {
                if (strpos($code, $prefix) !== 0) {
                    return null; // 前缀不匹配
                }
                $code = substr($code, strlen($prefix)); // 移除前缀
            }

            // 2. 拆分
            $format_parts = explode('-', $format_str); // [ 'P', 'A', 'M', 'T' ]
            $code_parts = explode($separator, $code);   // [ '101', '1', '1', '11' ]

            // 3. 验证长度
            if (count($format_parts) !== count($code_parts)) {
                return null; // 长度不匹配
            }

            // 4. 映射
            $result = ['p' => '', 'a' => null, 'm' => null, 't' => null];
            foreach ($format_parts as $index => $part_key) {
                $part_key_lower = strtolower($part_key); // 'p', 'a', 'm', 't'
                $value = $code_parts[$index] ?? null;
                
                if (array_key_exists($part_key_lower, $result)) {
                    // 只在值非空时才覆盖 null
                    if ($value !== null && $value !== '') {
                        $result[$part_key_lower] = $value;
                    }
                }
            }
            
            // P (产品) 必须存在
            return (!empty($result['p'])) ? $result : null;
        }

        /**
         * 解析 "键值对" 模式 (e.g., ?o=101&c=1)
         */
        private function _parseKeyValue(string $code, array $config): ?array {
            // 1. 解析 URL 查询字符串
            // (移除 ? # / 等)
            $code = ltrim($code, '?#/');
            parse_str($code, $query_params); // 自动处理 &
            
            if (empty($query_params)) {
                return null; // 不是有效的键值对
            }

            // 2. 映射
            $result = ['p' => '', 'a' => null, 'm' => null, 't' => null];
            $p_key = $config['P_key'] ?? 'p'; // P 键 (必填)
            $a_key = $config['A_key'] ?? '';  // A 键 (可选)
            $m_key = $config['M_key'] ?? '';  // M 键 (可选)
            $t_key = $config['T_key'] ?? '';  // T 键 (可选)

            if (empty($query_params[$p_key])) {
                return null; // 必须包含 P 键
            }
            
            $result['p'] = $query_params[$p_key] ?? '';
            
            if (!empty($a_key) && !empty($query_params[$a_key])) {
                $result['a'] = $query_params[$a_key];
            }
            if (!empty($m_key) && !empty($query_params[$m_key])) {
                $result['m'] = $query_params[$m_key];
            }
            if (!empty($t_key) && !empty($query_params[$t_key])) {
                $result['t'] = $query_params[$t_key];
            }

            return $result;
        }

        /**
         * 公共方法：解析查询码
         * @param string $raw_code 原始查询码 (来自 KDS JS 或扫码枪)
         * @return array|null 成功则返回 ['p'=>'101', 'a'=>'1', 'm'=>'2', 't'=>'11']，失败则 null
         */
        public function parse(string $raw_code): ?array {
            $this->loadRules();
            
            $raw_code = trim($raw_code);
            if ($raw_code === '') {
                return null;
            }

            foreach ($this->rules as $rule) {
                try {
                    $config = json_decode($rule['config_json'], true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        // 跳过损坏的规则
                        error_log("KDS SOP Parser: Skipping rule ID {$rule['id']} due to invalid JSON.");
                        continue; 
                    }

                    $result = null;
                    
                    if ($rule['extractor_type'] === 'DELIMITER') {
                        $result = $this->_parseDelimiter($raw_code, $config);
                    } 
                    elseif ($rule['extractor_type'] === 'KEY_VALUE') {
                        $result = $this->_parseKeyValue($raw_code, $config);
                    }
                    
                    // 如果此规则成功匹配
                    if ($result !== null) {
                        $result['raw'] = $raw_code;
                        return $result; // 立即返回第一个匹配项
                    }

                } catch (Throwable $e) {
                    // 捕获单个规则解析的错误，防止中断循环
                    error_log("KDS SOP Parser: Error in rule ID {$rule['id']}: " . $e->getMessage());
                }
            }

            // 遍历完所有规则都未匹配
            return null;
        }
    }
} // end !class_exists
?>