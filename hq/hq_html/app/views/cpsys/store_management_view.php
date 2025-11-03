<div class="d-flex justify-content-end mb-3">
    <button class="btn btn-primary" id="create-btn" data-bs-toggle="offcanvas" data-bs-target="#data-drawer">
        <i class="bi bi-plus-circle me-2"></i>创建新门店
    </button>
</div>

<div class="card">
    <div class="card-header">门店列表</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>门店码</th>
                        <th>门店名称</th>
                        <th>票据系统</th>
                        <th>默认税率</th>
                        <th>状态</th>
                        <th class="text-end">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($stores)): ?>
                        <tr><td colspan="6" class="text-center">暂无门店数据。</td></tr>
                    <?php else: ?>
                        <?php foreach ($stores as $store): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($store['store_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($store['store_name']); ?></td>
                                <td>
                                    <?php if ($store['billing_system'] === 'TICKETBAI'): ?>
                                        <span class="badge text-bg-primary">TicketBAI</span>
                                    <?php elseif ($store['billing_system'] === 'VERIFACTU'): ?>
                                        <span class="badge" style="background-color: #6f42c1; color: white;">Veri*Factu</span>
                                    <?php elseif ($store['billing_system'] === 'NONE'): ?>
                                        <span class="badge text-bg-warning">不可开票</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary">未配置</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($store['default_vat_rate']); ?>%</td>
                                <td>
                                    <?php if ($store['is_active']): ?>
                                        <span class="badge text-bg-success">已激活</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary">已禁用</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a href="index.php?page=kds_user_management&store_id=<?php echo $store['id']; ?>" class="btn btn-sm btn-outline-info">管理账户</a>
                                    <button class="btn btn-sm btn-outline-primary edit-btn" data-id="<?php echo $store['id']; ?>" data-bs-toggle="offcanvas" data-bs-target="#data-drawer">编辑</button>
                                    <button class="btn btn-sm btn-outline-danger delete-btn" data-id="<?php echo $store['id']; ?>" data-name="<?php echo htmlspecialchars($store['store_name']); ?>">删除</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="offcanvas offcanvas-end" tabindex="-1" id="data-drawer" aria-labelledby="drawer-label">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="drawer-label">创建/编辑门店</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <form id="data-form">
            <input type="hidden" id="data-id" name="id">
            
            <h6 class="text-white-50">基础信息</h6>
            <div class="mb-3">
                <label for="store_code" class="form-label">门店码 <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="store_code" name="store_code" required>
            </div>
            <div class="mb-3">
                <label for="store_name" class="form-label">门店名称 <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="store_name" name="store_name" required>
            </div>
            <div class="mb-3">
                <label for="tax_id" class="form-label">门店税号 (NIF) <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="tax_id" name="tax_id" required>
            </div>
            <div class="mb-3">
                <label for="store_city" class="form-label">所在城市</label>
                <input type="text" class="form-control" id="store_city" name="store_city">
            </div>

            <hr>
            <h6 class="text-white-50">财务与票据</h6>
            <div class="mb-3">
                <label for="billing_system" class="form-label">票据合规系统 <span class="text-danger">*</span></label>
                <select class="form-select" id="billing_system" name="billing_system" required>
                    <option value="" selected disabled>-- 请为门店指定法规 --</option>
                    <option value="TICKETBAI">TicketBAI (巴斯克地区)</option>
                    <option value="VERIFACTU">Veri*Factu (西班牙国家标准)</option>
                    <option value="NONE">不可开票</option>
                </select>
            </div>
            <div class="row">
                <div class="col-4">
                    <div class="mb-3">
                        <label for="default_vat_rate" class="form-label">默认税率 (%) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" class="form-control" id="default_vat_rate" name="default_vat_rate" required>
                    </div>
                </div>
                <div class="col-4">
                    <div class="mb-3">
                        <label for="invoice_number_offset" class="form-label">票号起始值 <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="invoice_number_offset" name="invoice_number_offset" required>
                    </div>
                </div>
                <div class="col-4">
                    <div class="mb-3">
                        <label for="eod_cutoff_hour" class="form-label">日结截止时间 <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="eod_cutoff_hour" name="eod_cutoff_hour" min="0" max="23" required>
                        <div class="form-text">0-23点，例如: 3</div>
                    </div>
                </div>
            </div>

            <hr>
            <h6 class="text-white-50">门店打印机配置</h6>
            <div class="mb-3">
                <label for="printer_type" class="form-label">打印机类型</label>
                <select class="form-select" id="printer_type" name="printer_type">
                    <option value="NONE">不使用打印机</option>
                    <option value="WIFI">WIFI (IP/Socket)</option>
                    <option value="BLUETOOTH">蓝牙 (Bluetooth)</option>
                    <option value="USB">USB (安卓收银机)</option>
                </select>
            </div>
            
            <div id="printer_wifi_group" style="display: none;">
                <div class="row g-2">
                    <div class="col-8">
                        <div class="mb-3">
                            <label for="printer_ip" class="form-label">IP 地址 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="printer_ip" name="printer_ip" placeholder="例如: 192.168.1.100">
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="mb-3">
                            <label for="printer_port" class="form-label">端口 <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="printer_port" name="printer_port" placeholder="例如: 9100">
                        </div>
                    </div>
                </div>
            </div>

            <div id="printer_bt_group" style="display: none;">
                <div class="mb-3">
                    <label for="printer_mac" class="form-label">蓝牙 MAC 地址 <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="printer_mac" name="printer_mac" placeholder="例如: AA:BB:CC:DD:EE:FF">
                </div>
            </div>
            
            <div id="printer_usb_group" style="display: none;">
                <div class="alert alert-info">
                    安卓收银机将自动查找连接的 USB 打印机。
                </div>
            </div>
            <hr>
            <div class="form-check form-switch mb-4">
                <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" checked>
                <label class="form-check-label" for="is_active">门店是否激活</label>
            </div>
            
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="offcanvas">取消</button>
                <button type="submit" class="btn btn-primary">保存到云端</button>
                <button type="button" class="btn btn-info" id="btn-sync-device" title="将当前设置保存并同步到安卓设备">
                    <i class="bi bi-hdd-stack"></i> 同步到设备
                </button>
            </div>
        </form>
    </div>
</div>