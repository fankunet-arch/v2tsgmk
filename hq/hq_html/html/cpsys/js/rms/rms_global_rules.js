/**
 * Toptea HQ - JavaScript for RMS Global Rules (Layer 2)
 * Engineer: Gemini | Date: 2025-11-02
 * Revision: 2.0 (Added Base Quantity Conditions)
 */
$(document).ready(function() {
    
    // (V2.2 PATH FIX) - This path is relative to index.php
    const API_URL = 'api/rms/rms_global_rules_handler.php';

    const dataDrawerEl = document.getElementById('global-rule-drawer');
    const dataDrawer = new bootstrap.Offcanvas(dataDrawerEl);
    const form = $('#global-rule-form');
    const drawerLabel = $('#drawer-label');
    const dataIdInput = $('#rule-id');
    const actionTypeSelect = $('#action_type');
    const actionUnitWrapper = $('#action-unit-wrapper');

    // --- Helper function to toggle unit visibility ---
    function toggleActionUnitField() {
        if (actionTypeSelect.val() === 'ADD_MATERIAL') {
            actionUnitWrapper.show();
        } else {
            actionUnitWrapper.hide();
        }
    }

    // --- Bind Event Listeners ---
    actionTypeSelect.on('change', toggleActionUnitField);

    $('#create-rule-btn').on('click', function() {
        drawerLabel.text('创建新全局规则');
        form[0].reset();
        dataIdInput.val('');
        $('#is_active').prop('checked', true);
        $('#priority').val('100');
        toggleActionUnitField(); // Reset visibility
    });

    $('.table').on('click', '.edit-btn', function() {
        const dataId = $(this).data('id');
        drawerLabel.text('编辑全局规则');
        form[0].reset();
        dataIdInput.val(dataId);

        $.ajax({
            url: API_URL, // (V2.2 PATH FIX)
            type: 'GET',
            data: { action: 'get', id: dataId },
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    const rule = response.data;
                    $('#rule_name').val(rule.rule_name);
                    $('#priority').val(rule.priority);
                    $('#is_active').prop('checked', rule.is_active == 1);
                    
                    $('#cond_cup_id').val(rule.cond_cup_id);
                    $('#cond_ice_id').val(rule.cond_ice_id);
                    $('#cond_sweet_id').val(rule.cond_sweet_id);
                    $('#cond_material_id').val(rule.cond_material_id);
                    
                    // ★★★ 新增字段 START ★★★
                    $('#cond_base_gt').val(rule.cond_base_gt);
                    $('#cond_base_lte').val(rule.cond_base_lte);
                    // ★★★ 新增字段 END ★★★
                    
                    actionTypeSelect.val(rule.action_type);
                    $('#action_material_id').val(rule.action_material_id);
                    $('#action_value').val(rule.action_value);
                    $('#action_unit_id').val(rule.action_unit_id);
                    
                    toggleActionUnitField(); // Set visibility based on loaded data
                } else {
                    alert('获取规则数据失败: ' + response.message);
                    dataDrawer.hide();
                }
            },
            error: function() {
                alert('获取规则数据时发生网络错误。');
                dataDrawer.hide();
            }
        });
    });

    form.on('submit', function(e) {
        e.preventDefault();

        // Serialize form data into an object
        const formDataArray = $(this).serializeArray();
        const formData = {};
        $.each(formDataArray, function() {
            formData[this.name] = this.value || '';
        });
        // Handle checkbox
        formData['is_active'] = $('#is_active').is(':checked') ? 1 : 0;

        $.ajax({
            url: API_URL, // (V2.2 PATH FIX)
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ action: 'save', data: formData }),
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    alert(response.message);
                    window.location.reload();
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
    
    $('.table').on('click', '.delete-btn', function() {
        const dataId = $(this).data('id');
        const dataName = $(this).data('name');

        if (confirm(`您确定要删除规则 "${dataName}" 吗？此操作不可撤销。`)) {
            $.ajax({
                url: API_URL, // (V2.2 PATH FIX)
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ action: 'delete', id: dataId }),
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        alert(response.message);
                        window.location.reload();
                    } else {
                        alert('删除失败: ' + response.message);
                    }
                },
                error: function(jqXHR) {
                    const errorMsg = jqXHR.responseJSON ? jqXHR.responseJSON.message : '删除过程中发生网络或服务器错误。';
                    alert('操作失败: ' + errorMsg);
                }
            });
        }
    });
});