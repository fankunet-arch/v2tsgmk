/**
 * Toptea HQ - JavaScript for SIF Declaration Page
 * Engineer: Gemini | Date: 2025-11-03
 *
 * [FIX 2.0]
 * - 修复了 loadDeclaration 在 response.data.declaration_text 为空字符串 ('') 时，
 * if ('') 判断为 false 导致无法清空 textarea 的问题。
 * - 检查条件从 (response.data.declaration_text) 
 * 修改为 (response.data && response.data.declaration_text !== null && response.data.declaration_text !== undefined)
 * - 这个检查现在可以正确处理来自API的 '' (空字符串)，同时会忽略 null (未找到记录)。
 */
$(document).ready(function() {
    const form = $('#sif-declaration-form');
    const feedbackDiv = $('#settings-feedback');
    const declarationTextarea = $('#sif_declaration_text');

    // Function to load settings
    function loadDeclaration() {
        feedbackDiv.html('<div class="spinner-border spinner-border-sm text-secondary" role="status"><span class="visually-hidden">Loading...</span></div>');
        $.ajax({
            url: 'api/sif_declaration_handler.php',
            type: 'GET',
            data: { action: 'load' },
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    
                    // [FIX 2.0] 检查 key 是否存在且不为 null/undefined
                    // response.data.declaration_text 可能是 '' (空字符串) -> 必须更新
                    // response.data.declaration_text 可能是 null (未找到) -> 必须跳过
                    if (response.data && response.data.declaration_text !== null && response.data.declaration_text !== undefined) {
                        declarationTextarea.val(response.data.declaration_text);
                    }
                    // 如果 API 返回 null (未找到)，此 if 将为 false，JS 不会执行任何操作，
                    // 保留由 PHP 视图渲染的默认模板。
                    
                    feedbackDiv.empty(); // Clear loading indicator
                } else {
                    feedbackDiv.html(`<div class="alert alert-danger">加载声明失败: ${response.message || '未知错误'}</div>`);
                }
            },
            error: function(jqXHR) {
                 const errorMsg = jqXHR.responseJSON ? jqXHR.responseJSON.message : '加载时发生网络错误。';
                 feedbackDiv.html(`<div class="alert alert-danger">${errorMsg}</div>`);
            }
        });
    }

    // Function to save settings
    form.on('submit', function(e) {
        e.preventDefault();
        feedbackDiv.html('<div class="spinner-border spinner-border-sm text-primary" role="status"><span class="visually-hidden">Saving...</span></div>');
        
        const submitButton = $(this).find('button[type="submit"]');
        submitButton.prop('disabled', true);

        const declarationText = declarationTextarea.val();

        $.ajax({
            url: 'api/sif_declaration_handler.php',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ action: 'save', declaration_text: declarationText }),
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    feedbackDiv.html('<div class="alert alert-success">声明已成功保存！</div>');
                     setTimeout(() => feedbackDiv.empty(), 3000); // Clear feedback after 3s
                } else {
                    feedbackDiv.html(`<div class="alert alert-danger">保存失败: ${response.message || '未知错误'}</div>`);
                }
            },
            error: function(jqXHR) {
                const errorMsg = jqXHR.responseJSON ? jqXHR.responseJSON.message : '保存过程中发生网络或服务器错误。';
                feedbackDiv.html(`<div class="alert alert-danger">操作失败: ${errorMsg}</div>`);
            },
            complete: function() {
                 submitButton.prop('disabled', false);
                 if (!feedbackDiv.find('.alert-success').length) {
                     setTimeout(() => feedbackDiv.empty(), 5000);
                 }
            }
        });
    });

    // Initial load
    loadDeclaration();
});