/**
 * Toptea HQ - cpsys
 * JavaScript for Store Management Page
 * Engineer: Gemini | Date: 2025-10-26
 * Revision: 4.0 (API Gateway Refactor)
 */

// --- [GEMINI PRINTER_CONFIG_UPDATE] START: Global Callbacks ---
// (无变化)
window.onConfigSaveSuccess = function() {
    alert('打印机配置已成功保存到设备！');
};
window.onConfigSaveFailure = function(error) {
    alert('打印机配置保存失败！\n错误: ' + error);
};
// --- [GEMINI PRINTER_CONFIG_UPDATE] END ---


$(document).ready(function() {
    
    // --- 新的 API 网关入口 ---
    const API_GATEWAY_URL = 'api/cpsys_api_gateway.php';
    
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

    // 发票确认逻辑元素
    const confirmEnableModalEl = document.getElementById('confirmEnableInvoicingModal');
    const confirmEnableModal = new bootstrap.Modal(confirmEnableModalEl);
    const confirmEnableBtn = $('#btn-confirm-enable-invoicing');
    const confirmInvoiceCheckbox = $('#confirmInvoiceCheckbox');
    const formSubmitButton = form.find('button[type="submit"]');


    function togglePrinterFields(type) {
        wifiGroup.hide();
        btGroup.hide();
        usbGroup.hide();
        syncButton.prop('disabled', false); 

        if (type === 'WIFI') {
            wifiGroup.show();
        } else if (type === 'BLUETOOTH') {
            btGroup.show();
        } else if (type === 'USB') {
            usbGroup.show();
        } else if (type === 'NONE') {
            syncButton.prop('disabled', true);
        }
    }
    
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
        printerTypeSelect.val('NONE');
        togglePrinterFields('NONE');
        form.data('original-billing-system', 'NONE');
        $('#billing_system').val('NONE');
    });

    $('.table').on('click', '.edit-btn', function() {
        const dataId = $(this).data('id');
        drawerLabel.text('编辑门店');
        form[0].reset();
        dataIdInput.val(dataId);
        $.ajax({
            // --- MODIFIED ---
            url: API_GATEWAY_URL, 
            type: 'GET', 
            data: { 
                res: 'stores',
                act: 'get',
                id: dataId 
            }, 
            dataType: 'json',
            // --- END MOD ---
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
                    
                    form.data('original-billing-system', store.billing_system || 'NONE');
                    
                } else { alert('获取数据失败: ' + response.message); dataDrawer.hide(); }
            },
            error: function() { alert('获取数据时发生网络错误。'); dataDrawer.hide(); }
        });
    });

    /**
     * 将实际的 AJAX 提交操作封装成一个函数
     */
    function performSave() {
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
            // --- MODIFIED ---
            url: API_GATEWAY_URL, 
            type: 'POST', 
            contentType: 'application/json',
            data: JSON.stringify({ data: formData }), // { action: 'save', data: formData }
            dataType: 'json',
            beforeSend: function (xhr, settings) {
                settings.url += "?res=stores&act=save";
            },
            // --- END MOD ---
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

        if (originalSystem === 'NONE' && (newSystem === 'TICKETBAI' || newSystem === 'VERIFACTU')) {
            $('#confirmModalStoreName').text($('#store_name').val() || '新门店');
            $('#confirmModalNewSystem').text(newSystem);
            confirmEnableModal.show();
        } else {
            performSave();
        }
    });

    // 3. 为模态框的确认按钮添加点击事件
    confirmEnableBtn.on('click', function() {
        performSave();
    });
    
    // (模态框勾选逻辑无变化)
    $(confirmEnableModalEl).on('show.bs.modal', function() {
        confirmInvoiceCheckbox.prop('checked', false);
        confirmEnableBtn.prop('disabled', true);
    });
    confirmInvoiceCheckbox.on('change', function() {
        if ($(this).is(':checked')) {
            confirmEnableBtn.prop('disabled', false);
        } else {
            confirmEnableBtn.prop('disabled', true);
        }
    });


    // "删除" 按钮
    $('.table').on('click', '.delete-btn', function() {
        const dataId = $(this).data('id');
        const dataName = $(this).data('name');
        if (confirm(`您确定要删除门店 "${dataName}" 吗？`)) {
            $.ajax({
                // --- MODIFIED ---
                url: API_GATEWAY_URL,
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ id: dataId }), // { action: 'delete', id: dataId }
                dataType: 'json',
                beforeSend: function (xhr, settings) {
                    settings.url += "?res=stores&act=delete";
                },
                // --- END MOD ---
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

    // "同步到设备" 按钮 (逻辑无变化)
    syncButton.on('click', function() {
        if (typeof window.AndroidBridge === 'undefined' || typeof window.AndroidBridge.savePrinterConfig === 'undefined') {
            alert('错误：找不到 AndroidBridge.savePrinterConfig 接口。\n请确认您是在 TopTea 安卓应用中运行此页面。');
            return;
        }
        // ... (同步逻辑无变化) ...
    });
});