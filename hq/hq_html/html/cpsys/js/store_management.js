/**
 * Toptea HQ - cpsys
 * JavaScript for Store Management Page
 * Engineer: Gemini | Date: 2025-10-26 | Revision: 3.0 (Printer Config Implementation)
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
    const dataDrawer = new bootstrap.Offcanvas(document.getElementById('data-drawer'));
    const form = $('#data-form');
    const drawerLabel = $('#drawer-label');
    const dataIdInput = $('#data-id');

    // --- [GEMINI PRINTER_CONFIG_UPDATE] START: Printer Field Refs ---
    const printerTypeSelect = $('#printer_type');
    const wifiGroup = $('#printer_wifi_group');
    const btGroup = $('#printer_bt_group');
    const usbGroup = $('#printer_usb_group');
    const syncButton = $('#btn-sync-device');

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
    // --- [GEMINI PRINTER_CONFIG_UPDATE] END ---


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
                    
                    // [GEMINI PRINTER_CONFIG_UPDATE] Load and display printer fields
                    const printerType = store.printer_type || 'NONE';
                    printerTypeSelect.val(printerType);
                    $('#printer_ip').val(store.printer_ip);
                    $('#printer_port').val(store.printer_port);
                    $('#printer_mac').val(store.printer_mac);
                    togglePrinterFields(printerType); // Show/hide fields based on loaded type
                    
                } else { alert('获取数据失败: ' + response.message); dataDrawer.hide(); }
            },
            error: function() { alert('获取数据时发生网络错误。'); dataDrawer.hide(); }
        });
    });

    // "保存到云端" 按钮
    form.on('submit', function(e) {
        e.preventDefault();
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
            
            // [GEMINI PRINTER_CONFIG_UPDATE] Add printer fields to save payload
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
                    alert(response.message); // "门店信息已成功更新！"
                    dataDrawer.hide();
                    // 注意：这里不自动刷新页面，以便用户可以接着点击“同步”
                    // window.location.reload(); 
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
            }
        });
    });
    
    // "删除" 按钮
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

    // --- [GEMINI PRINTER_CONFIG_UPDATE] START: "同步到设备" 按钮 ---
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
    // --- [GEMINI PRINTER_CONFIG_UPDATE] END ---
});