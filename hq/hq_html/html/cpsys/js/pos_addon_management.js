/**
 * TopTea HQ - JavaScript for POS Addon Management
 * Engineer: Gemini | Date: 2025-11-02
 */
$(document).ready(function() {
    const dataDrawer = new bootstrap.Offcanvas(document.getElementById('data-drawer'));
    const form = $('#data-form');
    const drawerLabel = $('#drawer-label');
    const dataIdInput = $('#data-id');
    const codeInput = $('#addon_code');

    $('#create-btn').on('click', function() {
        drawerLabel.text('创建新加料');
        form[0].reset();
        dataIdInput.val('');
        codeInput.prop('readonly', false);
        $('#is_active').prop('checked', true);
        $('#price_eur').val('0.50');
        $('#sort_order').val('99');
    });

    $('.table').on('click', '.edit-btn', function() {
        const dataId = $(this).data('id');
        drawerLabel.text('编辑加料');
        form[0].reset();
        dataIdInput.val(dataId);
        codeInput.prop('readonly', true); // Prevent changing the key

        $.ajax({
            url: 'api/pos_addon_handler.php',
            type: 'GET',
            data: { action: 'get', id: dataId },
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    const addon = response.data;
                    codeInput.val(addon.addon_code);
                    $('#name_zh').val(addon.name_zh);
                    $('#name_es').val(addon.name_es);
                    $('#price_eur').val(addon.price_eur);
                    $('#material_id').val(addon.material_id);
                    $('#sort_order').val(addon.sort_order);
                    $('#is_active').prop('checked', addon.is_active == 1);
                } else {
                    alert('获取数据失败: ' + response.message);
                    dataDrawer.hide();
                }
            },
            error: function() {
                alert('获取数据时发生网络错误。');
                dataDrawer.hide();
            }
        });
    });

    form.on('submit', function(e) {
        e.preventDefault();
        const formData = {
            id: dataIdInput.val(),
            addon_code: codeInput.val(),
            name_zh: $('#name_zh').val(),
            name_es: $('#name_es').val(),
            price_eur: $('#price_eur').val(),
            material_id: $('#material_id').val(),
            sort_order: $('#sort_order').val(),
            is_active: $('#is_active').is(':checked') ? 1 : 0
        };
        $.ajax({
            url: 'api/pos_addon_handler.php',
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
                if (jqXHR.responseJSON && jqXHR.responseJSON.message) {
                    alert('操作失败: ' + jqXHR.responseJSON.message);
                } else {
                    alert('保存过程中发生网络或服务器错误。');
                }
            }
        });
    });

    $('.table').on('click', '.delete-btn', function() {
        const dataId = $(this).data('id');
        const dataName = $(this).data('name');
        if (confirm(`您确定要删除加料 "${dataName}" 吗？`)) {
            $.ajax({
                url: 'api/pos_addon_handler.php',
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
});