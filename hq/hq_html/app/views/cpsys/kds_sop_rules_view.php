<?php
/**
 * Toptea HQ - KDS SOP Query Rule Management View
 * Engineer: Gemini | Date: 2025-11-05
 */
?>
<div class="d-flex justify-content-end mb-3">
    <button class="btn btn-primary" id="create-rule-btn" data-bs-toggle="offcanvas" data-bs-target="#rule-drawer">
        <i class="bi bi-plus-circle me-2"></i>创建新解析规则
    </button>
</div>

<div class="card">
    <div class="card-header">
        KDS SOP 解析规则列表
    </div>
    <div class="card-body">
        <div class="alert alert-info">
            <h4 class="alert-heading">规则说明</h4>
            <p>此页面定义了 KDS（厨房显示器）如何解析二维码或手动输入的SOP查询码。</p>
            <ul>
                <li><b>全局规则 (store_id 为 NULL):</b> 适用于所有门店，作为后备规则。</li>
                <li><b>门店专属规则:</b> 仅适用于指定门店，且优先级高于全局规则。</li>
                <li><b>优先级:</b> 数字越小，越先被尝试解析。</li>
                <li><b>内部规则 (ID=1):</b> 是 KDS JS 依赖的默认规则 (P-A-M-T)，请勿删除。</li>
            </ul>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>适用门店</th>
                        <th>规则名称</th>
                        <th>优先级</th>
                        <th>解析器类型</th>
                        <th>状态</th>
                        <th class="text-end">操作</th>
                    </tr>
                </thead>
                <tbody id="rules-table-body">
                    <tr><td colspan="6" class="text-center"><div class="spinner-border spinner-border-sm"></div> 正在加载...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="offcanvas offcanvas-end" tabindex="-1" id="rule-drawer" aria-labelledby="drawer-label" style="width: 600px;">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="drawer-label">创建/编辑规则</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <form id="rule-form">
            <input type="hidden" id="rule-id" name="id">

            <div class="card mb-3">
                <div class="card-header">基本信息</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="rule_name" class="form-label">规则名称 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="rule_name" name="rule_name" placeholder="例如: 门店A扫码枪 (P|M|T)" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="store_id" class="form-label">适用门店</label>
                        <select class="form-select" id="store_id" name="store_id">
                            <option value="">[ 全局规则 ] (适用于所有门店)</option>
                            <?php foreach ($stores as $store): ?>
                                <option value="<?php echo $store['id']; ?>"><?php echo htmlspecialchars($store['store_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="row">
                        <div class="col-6">
                            <label for="priority" class="form-label">优先级 <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="priority" name="priority" value="100" required>
                            <div class="form-text">数字越小，越先尝试。</div>
                        </div>
                        <div class="col-6 d-flex align-items-end">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" checked>
                                <label class="form-check-label" for="is_active">启用此规则</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">解析器配置</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="extractor_type" class="form-label">解析器类型 <span class="text-danger">*</span></label>
                        <select class="form-select" id="extractor_type" name="extractor_type" required>
                            <option value="" disabled selected>-- 请选择解析器 --</option>
                            <option value="DELIMITER">分隔符模式 (e.g., P-A-M-T)</option>
                            <option value="KEY_VALUE">URL参数模式 (e.g., ?p=101&a=1)</option>
                        </select>
                    </div>

                    <div id="config-delimiter" class="config-section" style="display: none;">
                        <div class="mb-3">
                            <label for="config_format" class="form-label">组件顺序 <span class="text-danger">*</span></label>
                            <select class="form-select" id="config_format" name="config_format">
                                <option value="P-A-M-T">P-A-M-T (产品-杯型-冰量-甜度)</option>
                                <option value="P-M-A-T">P-M-A-T (产品-冰量-杯型-甜度)</option>
                                <option value="P-A-M">P-A-M (产品-杯型-冰量)</option>
                                <option value="P-M-T">P-M-T (产品-冰量-甜度)</option>
                                <option value="P-A">P-A (产品-杯型)</option>
                                <option value="P">P (仅产品)</option>
                            </select>
                            <div class="form-text">P=产品, A=杯型, M=冰量, T=甜度</div>
                        </div>
                        <div class="mb-3">
                            <label for="config_separator" class="form-label">分隔符 (单字符) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="config_separator" name="config_separator" maxlength="1" placeholder="例如: - 或 |">
                        </div>
                        <div class="mb-3">
                            <label for="config_prefix" class="form-label">前缀 (可选)</label>
                            <input type="text" class="form-control" id="config_prefix" name="config_prefix" placeholder="例如: # 或 ~">
                            <div class="form-text">如果查询码以特定字符开头 (如 #101-1)，请填入。</div>
                        </div>
                    </div>

                    <div id="config-key-value" class="config-section" style="display: none;">
                        <div class="alert alert-secondary">
                            用于解析 <code>?key1=val1&key2=val2...</code> 格式的字符串。<br>
                            P(产品) 是必须的，其他为可选。
                        </div>
                        <div class="mb-3">
                            <label for="config_p_key" class="form-label">P (产品) 的键 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="config_p_key" name="config_p_key" placeholder="e.g., p 或 product_id">
                            <div class="form-text">示例: <code>?p=101</code> 中的 <code>p</code></div>
                        </div>
                        <div class="mb-3">
                            <label for="config_a_key" class="form-label">A (杯型) 的键</label>
                            <input type="text" class="form-control" id="config_a_key" name="config_a_key" placeholder="e.g., c 或 cup_code">
                            <div class="form-text">示例: <code>?c=1</code> 中的 <code>c</code>. 留空表示不解析此项。</div>
                        </div>
                        <div class="mb-3">
                            <label for="config_m_key" class="form-label">M (冰量) 的键</label>
                            <input type="text" class="form-control" id="config_m_key" name="config_m_key" placeholder="e.g., i 或 ice_code">
                            <div class="form-text">示例: <code>?i=2</code> 中的 <code>i</code>. 留空表示不解析此项。</div>
                        </div>
                        <div class="mb-3">
                            <label for="config_t_key" class="form-label">T (甜度) 的键</label>
                            <input type="text" class="form-control" id="config_t_key" name="config_t_key" placeholder="e.g., t 或 sugar_code">
                            <div class="form-text">示例: <code>?t=3</code> 中的 <code>t</code>. 留空表示不解析此项。</div>
                        </div>
                        
                        <hr>
                        <label class="form-label">实时示例 (Live Example)</label>
                        <div class="alert alert-secondary" style="font-family: monospace; word-break: break-all;">
                            <span class="text-white-50">?</span><span id="live-example-p"></span><span id="live-example-a"></span><span id="live-example-m"></span><span id="live-example-t"></span>
                        </div>
                        <div class="form-text">
                            这是一个根据您上方输入的“键”生成的示例 URL。KDS 将尝试按此格式解析扫码枪输入。
                        </div>
                        </div>
                </div>
            </div>

            <div class="d-flex justify-content-end mt-4">
                <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="offcanvas">取消</button>
                <button type="submit" class="btn btn-primary">保存规则</button>
            </div>
        </form>
    </div>
</div>