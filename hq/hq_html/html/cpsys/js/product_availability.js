/**
* Toptea HQ - JavaScript for Product Availability Management
* Engineer: Gemini | Date: 2025-11-08
*/
$(document).ready(function() {

const API_GATEWAY_URL = 'api/cpsys_api_gateway.php';
const listContainer = $('#product-list-container');
const template = $('#product-card-template').html();
const searchSelect = $('#material-search-select');
const clearBtn = $('#clear-search-btn');

// Toast (如果需要，可以替换为更复杂的提示)
function showToast(message, isError = false) {
console.log((isError ? "Error: " : "Success: ") + message);
// 简单实现
const feedback = isError ?
$('<div class="alert alert-danger position-fixed top-0 end-0 m-3" style="z-index: 1100"></div>').text(message) :
$('<div class="alert alert-success position-fixed top-0 end-0 m-3" style="z-index: 1100"></div>').text(message);

$('body').append(feedback);
setTimeout(() => feedback.fadeOut(500, () => feedback.remove()), 3000);
}

/**
* 渲染单个产品卡片
* @param {object} product - 从API获取的产品对象
*/
function renderProductCard(product) {
const $card = $(template);

// [FIX 1] 合并产品名称和 P-Code
$card.find('.product-name').html(`${product.name_zh} <span class="text-muted fw-normal">(P-Code: ${product.product_code || 'N/A'})</span>`);
// [FIX 1] 移除模板中原始的 <small> ID 标签
$card.find('.product-id').remove();

const $toggle = $card.find('.product-active-toggle');
const $label = $card.find('.product-status-label');

$toggle.prop('checked', product.is_active == 1);
$label.text(product.is_active == 1 ? '已上架' : '已下架');
$card.find('.card').toggleClass('border-success', product.is_active == 1);

$toggle.data('product-id', product.id);

const $materialsList = $card.find('.product-materials-list');
$materialsList.empty();
if (product.materials && product.materials.length > 0) {
product.materials.forEach(mat => {
// [FIX 2] 确保动态徽章为 text-bg-success (绿色)
$materialsList.append(`
<li class="d-inline-block me-1 mb-1">
<span class="badge text-bg-success">${mat.name_zh}</span>
</li>
`);
});
} else {
$materialsList.append(`<li><small class="text-muted">(无核心物料)</small></li>`);
}

listContainer.append($card);
}

/**
* 加载产品列表
* @param {string|null} materialId - 按物料ID过滤，或null加载全部
*/
function loadProducts(materialId = null) {
listContainer.html('<div class="col-12 text-center p-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>');

let url = `${API_GATEWAY_URL}?res=pos_menu_items&act=get_with_materials`;
if (materialId) {
url += `&material_id=${materialId}`;
}

$.ajax({
url: url,
type: 'GET',
dataType: 'json',
success: function(response) {
listContainer.empty();
if (response.status === 'success' && response.data && response.data.length > 0) {
response.data.forEach(product => {
renderProductCard(product);
});
} else if (response.status === 'success') {
listContainer.html('<div class="col-12 text-center p-5"><h5 class="text-muted">未找到匹配的产品。</h5></div>');
} else {
listContainer.html(`<div class="alert alert-danger">${response.message}</div>`);
}
},
error: function() {
listContainer.html('<div class="alert alert-danger">加载产品列表时发生网络错误。</div>');
}
});
}

// 事件：切换上架/下架状态
listContainer.on('change', '.product-active-toggle', function() {
const $toggle = $(this);
const $label = $toggle.closest('.form-switch').find('.product-status-label');
const productId = $toggle.data('product-id');
const newState = $toggle.is(':checked') ? 1 : 0;

$toggle.prop('disabled', true);
$label.text('保存中...');

$.ajax({
url: `${API_GATEWAY_URL}?res=pos_menu_items&act=toggle_active`,
type: 'POST',
contentType: 'application/json',
data: JSON.stringify({
id: productId,
is_active: newState
}),
dataType: 'json',
success: function(response) {
if (response.status === 'success') {
showToast(`产品 #${productId} 已${newState ? '上架' : '下架'}`);
$label.text(newState ? '已上架' : '已下架');
$toggle.closest('.card').toggleClass('border-success', newState == 1);
} else {
showToast(`更新失败: ${response.message}`, true);
$toggle.prop('checked', !newState); // 恢复原状
}
},
error: function() {
showToast('更新失败：网络错误', true);
$toggle.prop('checked', !newState);
},
complete: function() {
$toggle.prop('disabled', false);
// 确保标签文本正确
$label.text($toggle.is(':checked') ? '已上架' : '已下架');
}
});
});

// 事件：按物料搜索
searchSelect.on('change', function() {
loadProducts($(this).val());
});

// 事件：清除搜索
clearBtn.on('click', function() {
searchSelect.val('');
loadProducts(null);
});

// 初始加载
loadProducts(null);
});