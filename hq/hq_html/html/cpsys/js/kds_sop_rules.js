/**
 * Toptea HQ - JavaScript for KDS SOP Rule Management
 * Engineer: Gemini | Date: 2025-11-05
 */
$(document).ready(function() {
    
    const API_GATEWAY_URL = 'api/cpsys_api_gateway.php';
    const API_RES = 'kds_sop_rules';

    const dataDrawer = new bootstrap.Offcanvas(document.getElementById('rule-drawer'));
    const form = $('#rule-form');
    const drawerLabel = $('#drawer-label');
    const tableBody = $('#rules-table-body');
    
    const ruleIdInput = $('#rule-id');
    const extractorTypeSelect = $('#extractor_type');
    const configDelimiterDiv = $('#config-delimiter');
    const configKeyValueDiv = $('#config-key-value');

    /**
     * [NEW] 更新 URL 示例 (Request 2)
     */
    function updateUrlExample() {
        const pKey = $('#config_p_key').val().trim();
        const aKey = $('#config_a_key').val().trim();
        const mKey = $('#config_m_key').val().trim();
        const tKey = $('#config_t_key').val().trim();

        $('#live-example-p').text(pKey ? `${pKey}=101` : '');
        $('#live-example-a').text(aKey ? `&${aKey}=1` : '');
        $('#live-example-m').text(mKey ? `&${mKey}=2` : '');
        $('#live-example-t').text(tKey ? `&${tKey}=3` : '');
    }

    /**
     * 加载规则列表
     */
    function loadRules() {
        tableBody.html('<tr><td colspan="6" class="text-center"><div class="spinner-border spinner-border-sm"></div> 正在加载...</td></tr>');
        
        $.ajax({
            url: API_GATEWAY_URL,
            type: 'GET',
            data: { 
                res: API_RES,
                act: 'get_list'
            },
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    renderRulesTable(response.data);
                } else {
                    tableBody.html(`<tr><td colspan="6" class="text-center text-danger">加载失败: ${response.message}</td></tr>`);
                }
            },
            error: function() {
                tableBody.html(`<tr><td colspan="6" class="text-center text-danger">加载失败: 网络错误。</td></tr>`);
            }
        });
    }

    /**
     * 渲染规则表格
     */
    function renderRulesTable(rules) {
        tableBody.empty();
        if (!rules || rules.length === 0) {
            tableBody.html('<tr><td colspan="6" class="text-center">暂无解析规则。请至少创建一个。</td></tr>');
            return;
        }

        rules.forEach(rule => {
            const storeName = rule.store_name 
                ? `<span class="badge text-bg-success">${rule.store_name}</span>` 
                : `<span class="badge text-bg-info">全局规则</span>`;
            
            const status = rule.is_active
                ? `<span class="badge text-bg-success">已启用</span>`
                : `<span class="badge text-bg-secondary">已禁用</span>`;
            
            const rowHtml = `
                <tr ${rule.id === 1 ? 'class="table-internal-rule"' : ''}>
                    <td>${storeName}</td>
                    <td>
                        <strong>${escapeHTML(rule.rule_name)}</strong>
                        ${rule.id === 1 ? '<br><small class="text-muted">(KDS 内部 JS 依赖此规则)</small>' : ''}
                    </td>
                    <td><span class="badge text-bg-secondary">${rule.priority}</span></td>
                    <td><span class="badge text-bg-primary">${rule.extractor_type}</span></td>
                    <td>${status}</td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-primary edit-btn" data-id="${rule.id}" data-bs-toggle="offcanvas" data-bs-target="#rule-drawer">编辑</button>
                        <button class="btn btn-sm btn-outline-danger delete-btn" data-id="${rule.id}" data-name="${escapeHTML(rule.rule_name)}" ${rule.id === 1 ? 'disabled' : ''}>删除</button>
                    </td>
                </tr>
            `;
            tableBody.append(rowHtml);
        });
    }

    /**
     * 切换配置区域的可见性
     */
    function toggleConfigSections() {
        const type = extractorTypeSelect.val();
        configDelimiterDiv.hide();
        configKeyValueDiv.hide();
        if (type === 'DELIMITER') {
            configDelimiterDiv.show();
        } else if (type === 'KEY_VALUE') {
            configKeyValueDiv.show();
        }
    }
    extractorTypeSelect.on('change', toggleConfigSections);

    /**
     * 重置表单
     */
    function resetForm() {
        form[0].reset();
        ruleIdInput.val('');
        drawerLabel.text('创建新解析规则');
        $('#is_active').prop('checked', true);
        $('#priority').val('100');
        extractorTypeSelect.val('DELIMITER'); // 默认选中
        toggleConfigSections();
        updateUrlExample(); // [NEW] Call to clear/update preview
    }

    // --- 事件绑定 ---

    // --- [NEW] Event binding for Live URL Example (Request 2) ---
    form.on('input', '#config_p_key, #config_a_key, #config_m_key, #config_t_key', updateUrlExample);

    // 创建
    $('#create-rule-btn').on('click', resetForm);

    // 编辑
    tableBody.on('click', '.edit-btn', function() {
        resetForm();
        const ruleId = $(this).data('id');
        drawerLabel.text('编辑解析规则');
        ruleIdInput.val(ruleId);

        $.ajax({
            url: API_GATEWAY_URL,
            type: 'GET',
            data: { 
                res: API_RES,
                act: 'get',
                id: ruleId
            },
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    const rule = response.data;
                    $('#rule_name').val(rule.rule_name);
                    $('#store_id').val(rule.store_id || '');
                    $('#priority').val(rule.priority);
                    $('#is_active').prop('checked', rule.is_active == 1);
                    extractorTypeSelect.val(rule.extractor_type);

                    // 填充配置
                    if (rule.extractor_type === 'DELIMITER' && rule.config) {
                        $('#config_format').val(rule.config.format);
                        $('#config_separator').val(rule.config.separator);
                        $('#config_prefix').val(rule.config.prefix);
                    } else if (rule.extractor_type === 'KEY_VALUE' && rule.config) {
                        $('#config_p_key').val(rule.config.P_key);
                        $('#config_a_key').val(rule.config.A_key);
                        $('#config_m_key').val(rule.config.M_key);
                        $('#config_t_key').val(rule.config.T_key);
                    }
                    toggleConfigSections(); // 显示正确的DIV
                    updateUrlExample(); // [NEW] Call to update preview
                } else {
                    alert('获取规则失败: ' + response.message);
                    dataDrawer.hide();
                }
            },
            error: function() {
                alert('获取规则时发生网络错误。');
                dataDrawer.hide();
            }
        });
    });

    // 保存 (创建或更新)
    form.on('submit', function(e) {
        e.preventDefault();
        
        const formData = {
            id: ruleIdInput.val(),
            rule_name: $('#rule_name').val(),
            store_id: $('#store_id').val(),
            priority: $('#priority').val(),
            is_active: $('#is_active').is(':checked') ? 1 : 0,
            extractor_type: extractorTypeSelect.val(),
            
            // Delimiter config
            config_format: $('#config_format').val(),
            config_separator: $('#config_separator').val(),
            config_prefix: $('#config_prefix').val(),

            // Key-Value config
            config_p_key: $('#config_p_key').val(),
            config_a_key: $('#config_a_key').val(),
            config_m_key: $('#config_m_key').val(),
            config_t_key: $('#config_t_key').val()
        };

        $.ajax({
            url: API_GATEWAY_URL,
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ data: formData }),
            dataType: 'json',
            beforeSend: function (xhr, settings) {
                settings.url += `?res=${API_RES}&act=save`;
            },
            success: function(response) {
                if (response.status === 'success') {
                    alert(response.message);
                    dataDrawer.hide();
                    loadRules(); // 重新加载列表
                } else {
                    alert('保存失败: ' + (response.message || '未知错误'));
                }
            },
            error: function(jqXHR) {
                const errorMsg = jqXHR.responseJSON ? jqXHR.responseJSON.message : '保存过程中发生网络或服务器错误。';
                alert('操作失败: ' + errorMsg);
            }
        });
    });

    // 删除
    tableBody.on('click', '.delete-btn', function() {
        const ruleId = $(this).data('id');
        const ruleName = $(this).data('name');
        
        if (confirm(`您确定要删除规则 "${ruleName}" 吗？此操作不可撤销。`)) {
            $.ajax({
                url: API_GATEWAY_URL,
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ id: ruleId }),
                dataType: 'json',
                beforeSend: function (xhr, settings) {
                    settings.url += `?res=${API_RES}&act=delete`;
                },
                success: function(response) {
                    if (response.status === 'success') {
                        alert(response.message);
                        loadRules(); // 重新加载列表
                    } else {
                        alert('删除失败: ' + response.message);
                    }
                },
                error: function() {
                    alert('删除过程中发生网络或服务器错误。');
                }
            });
        }
    });
    
    function escapeHTML(str) {
        return String(str).replace(/[&<>"']/g, function(m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
        });
    }

    // 初始加载
    loadRules();
});