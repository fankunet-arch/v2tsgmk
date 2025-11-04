/**
 * Toptea HQ - cpsys
 * JavaScript for Store Management Page
 * Engineer: Gemini | Date: 2025-10-26
 * Revision: 3.0 (Printer Config Implementation)
 * Revision: 3.2 (Add Checkbox to Invoicing Confirmation Modal)
 */

// --- [GEMINI PRINTER_CONFIG_UPDATE] START: Global Callbacks ---
// 将回调函数暴露在全局作用域，以便 AndroidBridge 可以调用
window.onConfigSaveSuccess = function() {
    alert('打印机配置已成功保存到设备！');
};
window.onConfigSaveFailure = function(error) {
    alert('打印机配置保存失败！\n错误: ' + error);
};
// --- [GEMINI PRINTER_CONFIG_UPDATE] END ---


$(document).ready(function() {
    
    // --- 1. 获取所有需要的元素 ---
    const dataDrawerEl = document.getElementById('data-drawer');
    const dataDrawer = new bootstrap.Offcanvas(dataDrawerEl);
    const form = $('#data-form');
    const drawerLabel = $('#drawer-label');
    const dataIdInput = $('#data-id');

    // 打印机字段
    const printerTypeSelect = $('#printer_type');
    const wifiGroup = $('#printer_wifi_group');
    const btGroup = $('#printer_bt_group');
    const usbGroup = $('#printer_usb_group');
    const syncButton = $('#btn-sync-device');

    // --- START: 发票确认逻辑元素 ---
    const confirmEnableModalEl = document.getElementById('confirmEnableInvoicingModal');
    const confirmEnableModal = new bootstrap.Modal(confirmEnableModalEl);
    const confirmEnableBtn = $('#btn-confirm-enable-invoicing');
    const confirmInvoiceCheckbox = $('#confirmInvoiceCheckbox'); // <-- 新增
    const formSubmitButton = form.find('button[type="submit"]');
    // --- END: 发票确认逻辑元素 ---


    /**
     * 根据选择的打印机类型显示/隐藏相关输入框
     */
    function togglePrinterFields(type) {
        wifiGroup.hide();
        btGroup.hide();
        usbGroup.hide();
        syncButton.prop('disabled', false); // 默认启用

        if (type === 'WIFI') {
            wifiGroup.show();
        } else if (type === 'BLUETOOTH') {
            btGroup.show();
        } else if (type === 'USB') {
            usbGroup.show();
        } else if (type === 'NONE') {
            syncButton.prop('disabled', true); // "不使用" 时禁用同步按钮
        }
    }
    
    // 绑定打印机类型下拉框的 change 事件
    printerTypeSelect.on('change', function() {
        togglePrinterFields($(this).val());
    });


    $('#create-btn').on('click', function() {
        drawerLabel.text('创建新门店');
        form[0].reset();
        dataIdInput.val('');
        $('#is_active').prop('checked', true);
        $('#default_vat_rate').val('10.00');
        $('#invoice_number_offset').val('10000');
        $('#eod_cutoff_hour').val('3');
        
        // [GEMINI PRINTER_CONFIG_UPDATE] Reset printer fields
        printerTypeSelect.val('NONE');
        togglePrinterFields('NONE');

        // --- START: 新增逻辑 ---
        // 为新门店设置原始票据系统为 'NONE'
        form.data('original-billing-system', 'NONE');
        // 确保"不可开票"被选中
        $('#billing_system').val('NONE');
        // --- END: 新增逻辑 ---
    });

    $('.table').on('click', '.edit-btn', function() {
        const dataId = $(this).data('id');
        drawerLabel.text('编辑门店');
        form[0].reset();
        dataIdInput.val(dataId);
        $.ajax({
            url: 'api/store_handler.php', type: 'GET', data: { action: 'get', id: dataId }, dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    const store = response.data;
                    $('#store_code').val(store.store_code);
                    $('#store_name').val(store.store_name);
                    $('#tax_id').val(store.tax_id);
                    $('#billing_system').val(store.billing_system);
                    $('#default_vat_rate').val(store.default_vat_rate);
                    $('#invoice_number_offset').val(store.invoice_number_offset);
                    $('#eod_cutoff_hour').val(store.eod_cutoff_hour);
                    $('#store_city').val(store.store_city);
                    $('#is_active').prop('checked', store.is_active == 1);
                    
                    const printerType = store.printer_type || 'NONE';
                    printerTypeSelect.val(printerType);
                    $('#printer_ip').val(store.printer_ip);
                    $('#printer_port').val(store.printer_port);
                    $('#printer_mac').val(store.printer_mac);
                    togglePrinterFields(printerType);
                    
                    // --- START: 新增逻辑 ---
                    // 存储加载时的原始票据系统状态
                    form.data('original-billing-system', store.billing_system || 'NONE');
                    // --- END: 新增逻辑 ---
                    
                } else { alert('获取数据失败: ' + response.message); dataDrawer.hide(); }
            },
            error: function() { alert('获取数据时发生网络错误。'); dataDrawer.hide(); }
        });
    });

    /**
     * --- START: 重构保存逻辑 ---
     * 将实际的 AJAX 提交操作封装成一个函数
     */
    function performSave() {
        // 禁用所有提交按钮
        formSubmitButton.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>保存中...');
        confirmEnableBtn.prop('disabled', true);

        const formData = {
            id: dataIdInput.val(),
            store_code: $('#store_code').val(),
            store_name: $('#store_name').val(),
            tax_id: $('#tax_id').val(),
            billing_system: $('#billing_system').val(),
            default_vat_rate: $('#default_vat_rate').val(),
            invoice_number_offset: $('#invoice_number_offset').val(),
            eod_cutoff_hour: $('#eod_cutoff_hour').val(),
            store_city: $('#store_city').val(),
            is_active: $('#is_active').is(':checked') ? 1 : 0,
            printer_type: printerTypeSelect.val(),
            printer_ip: $('#printer_ip').val(),
            printer_port: $('#printer_port').val(),
            printer_mac: $('#printer_mac').val()
        };
        
        $.ajax({
            url: 'api/store_handler.php', type: 'POST', contentType: 'application/json',
            data: JSON.stringify({ action: 'save', data: formData }), dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    alert(response.message);
                    dataDrawer.hide();
                    confirmEnableModal.hide();
                    window.location.reload(); // 成功后刷新
                } else {
                    alert('保存失败: ' + (response.message || '未知错误'));
                }
            },
            error: function(jqXHR) {
                if (jqXHR.responseJSON && jqXHR.responseJSON.message) {
                    alert('操作失败: ' + jqXHR.responseJSON.message);
                } else {
                    alert('保存过程中发生网络或服务器错误。');
                }
            },
            complete: function() {
                // 恢复所有按钮状态
                formSubmitButton.prop('disabled', false).text('保存到云端');
                confirmEnableBtn.prop('disabled', false);
            }
        });
    }

    // 2. 修改表单提交事件，使其成为一个"网关"
    form.on('submit', function(e) {
        e.preventDefault();
        
        const originalSystem = form.data('original-billing-system') || 'NONE';
        const newSystem = $('#billing_system').val();

        // 检查是否是从 'NONE' 变为 'TICKETBAI' 或 'VERIFACTU'
        if (originalSystem === 'NONE' && (newSystem === 'TICKETBAI' || newSystem === 'VERIFACTU')) {
            // 是！需要二次确认
            $('#confirmModalStoreName').text($('#store_name').val() || '新门店');
            $('#confirmModalNewSystem').text(newSystem);
            confirmEnableModal.show();
        } else {
            // 否，直接保存 (例如：从 'NONE' 保存为 'NONE'，或从 'TICKETBAI' 保存为 'TICKETBAI')
            performSave();
        }
    });

    // 3. 为模态框的确认按钮添加点击事件
    confirmEnableBtn.on('click', function() {
        performSave(); // 调用实际的保存函数
    });
    // --- END: 重构保存逻辑 ---
    

    // --- START: 新增模态框勾选逻辑 ---
    
    // 4. 监听模态框的显示事件，重置勾选框和按钮
    $(confirmEnableModalEl).on('show.bs.modal', function() {
        confirmInvoiceCheckbox.prop('checked', false);
        confirmEnableBtn.prop('disabled', true);
    });

    // 5. 监听勾选框的 change 事件
    confirmInvoiceCheckbox.on('change', function() {
        if ($(this).is(':checked')) {
            confirmEnableBtn.prop('disabled', false);
        } else {
            confirmEnableBtn.prop('disabled', true);
        }
    });
    // --- END: 新增模态框勾选逻辑 ---


    // "删除" 按钮 (逻辑不变)
    $('.table').on('click', '.delete-btn', function() {
        const dataId = $(this).data('id');
        const dataName = $(this).data('name');
        if (confirm(`您确定要删除门店 "${dataName}" 吗？`)) {
            $.ajax({
                url: 'api/store_handler.php',
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
                error: function() {
                    alert('删除过程中发生网络或服务器错误。');
                }
            });
        }
    });

    // "同步到设备" 按钮 (逻辑不变)
    syncButton.on('click', function() {
        if (typeof window.AndroidBridge === 'undefined' || typeof window.AndroidBridge.savePrinterConfig === 'undefined') {
            alert('错误：找不到 AndroidBridge.savePrinterConfig 接口。\n请确认您是在 TopTea 安卓应用中运行此页面。');
            return;
        }

        const type = printerTypeSelect.val();
        let ip = "";
        let port = 0;
        let macAddress = "";

        try {
            switch (type) {
                case 'WIFI':
                    ip = $('#printer_ip').val().trim();
                    port = parseInt($('#printer_port').val(), 10);
                    if (!ip || !/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/.test(ip)) {
                        alert('WIFI 打印机需要一个有效的 IP 地址。');
                        return;
                    }
                    if (isNaN(port) || port <= 0 || port > 65535) {
                        alert('WIFI 打印机需要一个有效的端口号 (例如: 9100)。');
                        return;
                    }
                    
                    console.log(`Calling AndroidBridge.savePrinterConfig("WIFI", "${ip}", ${port}, "")`);
                    window.AndroidBridge.savePrinterConfig(
                        "WIFI",
                        ip,
                        port,
                        "", // macAddress is empty
                        "onConfigSaveSuccess",
                        "onConfigSaveFailure"
                    );
                    break;
                
                case 'BLUETOOTH':
                    macAddress = $('#printer_mac').val().trim().toUpperCase();
                    if (!macAddress || !/^([0-9A-F]{2}[:-]){5}([0-9A-F]{2})$/.test(macAddress)) {
                        alert('蓝牙打印机需要一个有效的 MAC 地址 (例如: AA:BB:CC:DD:EE:FF)。');
                        return;
                    }

                    console.log(`Calling AndroidBridge.savePrinterConfig("BLUETOOTH", "", 0, "${macAddress}")`);
                    window.AndroidBridge.savePrinterConfig(
                        "BLUETOOTH",
                        "", // ip is empty
                        0,  // port is 0
                        macAddress,
                        "onConfigSaveSuccess",
                        "onConfigSaveFailure"
                    );
                    break;

                case 'USB':
                    console.log(`Calling AndroidBridge.savePrinterConfig("USB", "", 0, "")`);
                    window.AndroidBridge.savePrinterConfig(
                        "USB",
                        "", // ip is empty
                        0,  // port is 0
                        "", // macAddress is empty
                        "onConfigSaveSuccess",
                        "onConfigSaveFailure"
                    );
                    break;
                
                case 'NONE':
                    alert('未配置打印机，无需同步。');
                    break;
            }
        } catch (e) {
            alert('调用 AndroidBridge 时发生 JavaScript 错误: \n' + e.message);
        }
    });
});