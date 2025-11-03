<?php
/**
 * Toptea HQ - SIF Compliance Declaration View
 * Engineer: Gemini | Date: 2025-11-03
 *
 * [FIX 2.0]
 * - 修复了 $sif_declaration_text 为空字符串 ('') 时，视图错误地加载默认模板的问题。
 * - 逻辑从 (!empty($sif_declaration_text)) 修改为 ($sif_declaration_text !== false)，
 * 以区分“未找到记录 (false)”和“已保存为空 (string '')”。
 * - (index.php 控制器传来的是 fetchColumn() 的结果，可能是 false, null 或 string)
 */

// Pre-fill the text area with the spanish template if it's empty
// $sif_declaration_text is passed in from index.php controller
$default_declaration_text = <<<TEXT
DECLARACIÓN RESPONSABLE DEL SISTEMA INFORMÁTICO DE FACTURACIÓN

a) Nombre del sistema: TOPTEA POS/KDS
b) Código identificador del SIF: SIF-TOPTEA-2025-V2
c) Versión: v2.5.0
d) Componentes HW/SW y funcionalidades principales:
   - HW: Terminales TPV Android, Impresoras térmicas.
   - SW: Módulo POS (store.toptea.es), Módulo KDS (store.toptea.es), Módulo CPSYS (hq.toptea.es).
   - Funcionalidades: Gestión de pedidos, cobros, emisión de facturas simplificadas (SIF), gestión de cocina (KDS), gestión de recetas (RMS) y configuración centralizada (CPSYS).
e) Modalidad de uso: [X] Dual (VERIFACTU y no verificable, según configuración de tienda)
f) Ámbito de uso: [X] Varios OT; [X] Multitienda/Multiterminal
g) Tipos de firma aplicados a RF y registro de eventos: Firma electrónica (Simulada para desarrollo)
h) Productor (nombre/razón social): [TU NOMBRE DE EMPRESA AQUÍ]
i) NIF/otro TIN y país: [TU NIF/CIF AQUÍ] (ESPAÑA)
j) Domicilio y datos de contacto del productor: [TU DIRECCIÓN COMPLETA AQUÍ], email: [TU EMAIL DE SOPORTE AQUÍ]
k) Manifestación de conformidad: El productor certifica, bajo su responsabilidad, que este SIF cumple el art. 29.2.j) de la Ley 58/2003, el RD 1007/2023 y la Orden HAC/1177/2024 (y demás normas de desarrollo).
l) Lugar y fecha: Bilbao, España / 03 / 11 / 2025
TEXT;

// [FIX 2.0] 使用 ($sif_declaration_text !== false) 来正确处理
// fetchColumn() 返回的 false (未找到) vs '' (空字符串)
$current_declaration_text = ($sif_declaration_text !== false) ? $sif_declaration_text : $default_declaration_text;
?>

<div class="row justify-content-center">
    <div class="col-lg-10">
        <form id="sif-declaration-form">
            <div class="card mb-4">
                <div class="card-header">
                    Gestión de la Declaración Responsable (SIF)
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <h4 class="alert-heading">Información Legal</h4>
                        <p>Este texto es la "Declaración Responsable" (Self-Declaration) requerida por la normativa española (RD 1007/2023 y Orden HAC/1177/2024). Debe ser accesible desde el terminal POS.</p>
                        <p>Por favor, revise y complete todos los campos con la información legal y técnica correcta de su empresa (Productor del SIF).</p>
                    </div>
                    <div class="mb-3">
                        <label for="sif_declaration_text" class="form-label">Contenido de la Declaración Responsable</label>
                        <textarea class="form-control" id="sif_declaration_text" name="sif_declaration_text" rows="25" style="font-family: monospace; font-size: 0.9rem;"><?php echo htmlspecialchars($current_declaration_text); ?></textarea>
                        <div class="form-text">Edite el contenido según los requisitos de la Orden HAC/1177/2024, Artículo 15.</div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end mt-4">
                <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-save me-2"></i> Guardar Declaración</button>
            </div>
        </form>
        <div id="settings-feedback" class="mt-3"></div>
    </div>
</div>