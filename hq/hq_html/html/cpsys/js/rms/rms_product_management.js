/**
 * Toptea HQ - RMS (Recipe Management System) JavaScript
 * Engineer: Gemini | Date: 2025-11-03
 * Revision: 1.7.001 (FIX: Restore full function bodies)
 */
$(document).ready(function() {

    // --- 新的 API 网关入口 ---
    const API_GATEWAY_URL = 'api/cpsys_api_gateway.php';

    const productListContainer = $('#product-list-container');
    const editorContainer = $('#product-editor-container');
    const templatesContainer = $('#rms-templates');

    // --- DELEGATED EVENT HANDLERS ---
    productListContainer.on('click', '.list-group-item-action', function(e) {
        e.preventDefault();
        productListContainer.find('.list-group-item-action').removeClass('active');
        $(this).addClass('active');
        loadProductEditor($(this).data('productId'));
    });

    $('#btn-add-product').on('click', function() {
        productListContainer.find('.list-group-item-action').removeClass('active');
        renderProductEditor(null);
        $.ajax({
            url: API_GATEWAY_URL, 
            type: 'GET', 
            data: { 
                res: 'rms_products',
                act: 'get_next_product_code' 
            }, 
            dataType: 'json',
            success: response => {
                if (response.status === 'success') {
                    $('#product_code').val(response.data.next_code);
                    $('.adjustment-rule-card').each(function() {
                        updatePamtCodeDisplay($(this));
                    });
                }
            }
        });
    });

    editorContainer.on('submit', '#product-form', function(e) { e.preventDefault(); saveProduct(); });

    editorContainer.on('click', '#btn-delete-product', function() {
        const productId = $('#product-id').val();
        if (!productId) { alert('这是一个新产品，尚未保存，无法删除。'); return; }
        if (confirm('您确定要删除这个产品及其所有配方吗？此操作不可撤销。')) {
            deleteProduct(productId);
        }
    });

    editorContainer.on('click', '#btn-add-base-recipe-row', () => addRecipeRow('#base-recipe-body'));
    editorContainer.on('click', '#btn-add-adjustment-rule', () => addAdjustmentRule());
    editorContainer.on('click', '.btn-remove-adjustment-rule', function() {
        $(this).closest('.adjustment-rule-card').remove();
        if ($('.adjustment-rule-card').length === 0) { $('#no-adjustments-placeholder').show(); }
    });
    editorContainer.on('click', '.btn-add-adjustment-recipe-row', function() {
        const targetBody = $(this).closest('.card-body').find('.adjustment-recipe-body');
        addRecipeRow(targetBody);
    });
    editorContainer.on('click', '.btn-remove-row', function() { $(this).closest('tr').remove(); });
    
    // --- PAMT Code Generation Handlers ---
    editorContainer.on('change', '.cup-condition, .sweetness-condition, .ice-condition', function() {
        const $ruleCard = $(this).closest('.adjustment-rule-card');
        updatePamtCodeDisplay($ruleCard);
    });
    editorContainer.on('change', '#product_code', function() {
        $('.adjustment-rule-card').each(function() {
            updatePamtCodeDisplay($(this));
        });
    });
    editorContainer.on('click', '.btn-copy-pamt', function() {
        const $input = $(this).closest('.input-group').find('input.pamt-code-display');
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText($input.val()).then(() => {
                feedback($(this));
            }).catch(err => {
                console.error('Clipboard copy failed:', err);
                fallbackCopy($input[0]); // Fallback
            });
        } else {
            fallbackCopy($input[0]);
        }
    });
    editorContainer.on('click', '.btn-show-pmat-list', function() {
        generatePmatListForRule($(this).closest('.adjustment-rule-card'));
    });

    function fallbackCopy(inputElement) {
        try {
            inputElement.select();
            document.execCommand('copy');
            feedback($(inputElement).next('.btn-copy-pamt'));
        } catch (e) {
            alert('复制失败');
        }
    }
    
    function feedback($button) {
        const $icon = $button.find('i');
        const originalIcon = $icon.attr('class');
        $icon.removeClass('bi-clipboard bi-list-task').addClass('bi-check-lg text-success');
        setTimeout(() => {
            $icon.removeClass('bi-check-lg text-success').addClass(originalIcon);
        }, 1500);
    }

    // --- CORE FUNCTIONS ---

    function updatePamtCodeDisplay($ruleCard) {
        const pCode = $('#product_code').val() || 'P';
        const $cupSelect = $ruleCard.find('.cup-condition');
        const $iceSelect = $ruleCard.find('.ice-condition');
        const $sweetSelect = $ruleCard.find('.sweetness-condition');
        const aCode = $cupSelect.find('option:selected').data('code') || '';
        const mCode = $iceSelect.find('option:selected').data('code') || '';
        const tCode = $sweetSelect.find('option:selected').data('code') || '';
        const pamtString = [pCode, aCode, mCode, tCode].filter(Boolean).join('-');
        $ruleCard.find('.pamt-code-display').val(pamtString);
    }

    function loadProductEditor(productId) {
        editorContainer.html('<div class="card-body text-center"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>');
        $.ajax({
            url: API_GATEWAY_URL, 
            type: 'GET', 
            data: { 
                res: 'rms_products',
                act: 'get_product_details',
                id: productId 
            }, 
            dataType: 'json',
            success: response => {
                if (response.status === 'success') { renderProductEditor(response.data); } 
                else { editorContainer.html(`<div class="alert alert-danger">${response.message}</div>`); }
            },
            error: () => editorContainer.html('<div class="alert alert-danger">加载产品数据时发生网络错误。</div>')
        });
    }

    // [FIX] 恢复此函数的完整内容
    function renderProductEditor(data) {
        editorContainer.html(templatesContainer.find('#editor-template').html());

        if (data) {
            // 1. Fill Base Info
            $('#editor-title').text(`${data.product_code} - ${data.name_zh}`);
            $('#product-id').val(data.id);
            $('#product_code').val(data.product_code);
            $('#name_zh').val(data.name_zh);
            $('#name_es').val(data.name_es);
            $('#status_id').val(data.status_id);

            // 2. Populate Gating Checkboxes
            if (data.allowed_sweetness_ids) {
                data.allowed_sweetness_ids.forEach(id => {
                    $(`#gating-sweetness-list input[value="${id}"]`).prop('checked', true);
                });
            }
            if (data.allowed_ice_ids) {
                data.allowed_ice_ids.forEach(id => {
                    $(`#gating-ice-list input[value="${id}"]`).prop('checked', true);
                });
            }

            // 3. Populate Base Recipes (L1)
            const baseRecipeBody = $('#base-recipe-body');
            if(data.base_recipes && data.base_recipes.length > 0){
                data.base_recipes.forEach(recipe => addRecipeRow(baseRecipeBody, recipe));
            } else {
                baseRecipeBody.html('<tr><td colspan="5" class="text-center text-muted">暂无基础配方步骤。</td></tr>');
            }
            
            // 4. Populate Overrides (L3)
            if(data.adjustments && data.adjustments.length > 0) {
                $('#no-adjustments-placeholder').hide();
                data.adjustments.forEach(ruleGroup => addAdjustmentRule(ruleGroup));
            }
        } else {
            $('#editor-title').text('新产品');
            $('#btn-delete-product').hide();
            $('#gating-sweetness-list input[type="checkbox"]').prop('checked', true);
            $('#gating-ice-list input[type="checkbox"]').prop('checked', true);
        }
    }

    // [FIX] 恢复此函数的完整内容
    function addRecipeRow(targetBody, data = null) {
        if ($(targetBody).find('td[colspan]').length) $(targetBody).empty();
        const $newRow = templatesContainer.find('#recipe-row-template').clone().removeAttr('id');
        if (data) {
            if (data.step_category) {
                $newRow.find('.step-category-select').val(data.step_category);
            }
            $newRow.find('.material-select').val(data.material_id);
            $newRow.find('.quantity-input').val(data.quantity);
            $newRow.find('.unit-select').val(data.unit_id);
        }
        $(targetBody).append($newRow);
    }

    // [FIX] 恢复此函数的完整内容
    function addAdjustmentRule(ruleGroup = null) {
        $('#no-adjustments-placeholder').hide();
        const $newRule = templatesContainer.find('#adjustment-rule-template').clone().removeAttr('id');
        
        if (ruleGroup) {
            $newRule.find('.cup-condition').val(ruleGroup.cup_id);
            $newRule.find('.sweetness-condition').val(ruleGroup.sweetness_option_id);
            $newRule.find('.ice-condition').val(ruleGroup.ice_option_id);
            
            const recipeBody = $newRule.find('.adjustment-recipe-body');
            if(ruleGroup.overrides && ruleGroup.overrides.length > 0){
                 ruleGroup.overrides.forEach(override => addRecipeRow(recipeBody, override));
            }
        }
        $('#adjustments-body').append($newRule);
        
        updatePamtCodeDisplay($newRule);
    }

    function saveProduct() {
        const allowed_sweetness_ids = $('#gating-sweetness-list input:checked').map(function() { return $(this).val(); }).get();
        const allowed_ice_ids = $('#gating-ice-list input:checked').map(function() { return $(this).val(); }).get();

        const productData = {
            id: $('#product-id').val() || null,
            product_code: $('#product_code').val(),
            name_zh: $('#name_zh').val(),
            name_es: $('#name_es').val(),
            status_id: $('#status_id').val(),
            allowed_sweetness_ids: allowed_sweetness_ids,
            allowed_ice_ids: allowed_ice_ids,
            base_recipes: [],
            adjustments: []
        };

        $('#base-recipe-body tr').each(function(index) {
            const row = $(this);
            if (row.find('td[colspan]').length) return;
            productData.base_recipes.push({
                step_category: row.find('.step-category-select').val(),
                material_id: row.find('.material-select').val(),
                quantity: row.find('.quantity-input').val(),
                unit_id: row.find('.unit-select').val(),
                sort_order: index
            });
        });

        $('.adjustment-rule-card').each(function() {
            const card = $(this);
            const cup_id = card.find('.cup-condition').val() || null;
            const sweetness_option_id = card.find('.sweetness-condition').val() || null;
            const ice_option_id = card.find('.ice-condition').val() || null;
            
            card.find('.adjustment-recipe-body tr').each(function() {
                const row = $(this);
                if (row.find('td[colspan]').length) return;
                 productData.adjustments.push({
                    cup_id: cup_id,
                    sweetness_option_id: sweetness_option_id,
                    ice_option_id: ice_option_id,
                    step_category: row.find('.step-category-select').val(),
                    material_id: row.find('.material-select').val(),
                    quantity: row.find('.quantity-input').val(),
                    unit_id: row.find('.unit-select').val()
                });
            });
        });

        $.ajax({
            url: API_GATEWAY_URL, 
            type: 'POST', 
            contentType: 'application/json', 
            data: JSON.stringify({ product: productData }),
            dataType: 'json',
            beforeSend: function (xhr, settings) {
                settings.url += "?res=rms_products&act=save_product";
            },
            success: response => {
                if (response.status === 'success') { alert(response.message); window.location.reload(); } 
                else { alert('保存失败: ' + response.message); }
            },
            error: (jqXHR) => {
                const errorMsg = jqXHR.responseJSON ? jqXHR.responseJSON.message : '保存过程中发生网络或服务器错误。';
                alert(`操作失败: ${errorMsg}`);
            }
        });
    }

    function deleteProduct(productId) {
        $.ajax({
            url: API_GATEWAY_URL, 
            type: 'POST', 
            contentType: 'application/json', 
            data: JSON.stringify({ id: productId }),
            dataType: 'json',
            beforeSend: function (xhr, settings) {
                settings.url += "?res=rms_products&act=delete_product";
            },
            success: response => {
                if (response.status === 'success') { alert(response.message); window.location.reload(); } 
                else { alert('删除失败: ' + response.message); }
            },
            error: () => alert('删除过程中发生网络错误。')
        });
    }
    
    // [FIX] 恢复此函数的完整内容
    function generatePmatListForRule($ruleCard) {
        const pCode = $('#product_code').val() || 'P';
        
        function getCodeFromGatingLabel(labelElement) {
            const match = $(labelElement).text().match(/\[(.*?)\]/);
            return match ? match[1] : null;
        }
        
        function getCodeList(type, $ruleSelect) {
            const ruleValue = $ruleSelect.val();
            
            if (ruleValue) {
                const specificCode = $ruleSelect.find('option:selected').data('code');
                return [specificCode];
            }
            
            let codeList = [];
            if (type === 'cup') {
                codeList = templatesContainer.find('.cup-condition').find('option[value!=""]').map((_, opt) => $(opt).data('code')).get();
            } else if (type === 'ice') {
                codeList = $('#gating-ice-list input:checked').map((_, cb) => {
                    return getCodeFromGatingLabel($(cb).closest('.form-check').find('label'));
                }).get();
            } else if (type === 'sweetness') {
                codeList = $('#gating-sweetness-list input:checked').map((_, cb) => {
                    return getCodeFromGatingLabel($(cb).closest('.form-check').find('label'));
                }).get();
            }
            
            return codeList.filter(Boolean);
        }

        const aList = getCodeList('cup', $ruleCard.find('.cup-condition'));
        const mList = getCodeList('ice', $ruleCard.find('.ice-condition'));
        const tList = getCodeList('sweetness', $ruleCard.find('.sweetness-condition'));

        let results = [];
        const finalAList = aList.length > 0 ? aList : [null];
        const finalMList = mList.length > 0 ? mList : [null];
        const finalTList = tList.length > 0 ? tList : [null];
        
        finalAList.forEach(a => {
            finalMList.forEach(m => {
                finalTList.forEach(t => {
                    results.push([pCode, a, m, t].filter(Boolean).join('-'));
                });
            });
        });
        
        const uniqueResults = [...new Set(results)];
        const modalTextarea = $('#pmat-list-textarea');
        
        if (uniqueResults.length > 0) {
             modalTextarea.val(uniqueResults.join('\n'));
        } else {
             modalTextarea.val("（无匹配）\n\n请检查：\n1. Gating设置是否至少勾选了一项？\n2. L3规则是否指定了Gating中未勾选的项？");
        }
        
        const ruleName = $ruleCard.find('.pamt-code-display').val();
        $('#pmat-list-modal-label').text(`匹配 [${ruleName}] 的PMAT码列表 (${uniqueResults.length}条)`);
    }
});