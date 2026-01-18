<?php
/**
 * Finance Dashboard Modals
 * 财务工作台弹窗组件
 */
?>

<div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="statusModalTitle">状态调整</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="statusForm" class="row g-3">
                    <input type="hidden" id="statusEntityType" value="">
                    <input type="hidden" id="statusEntityId" value="">
                    <div class="col-12">
                        <label class="form-label">目标状态</label>
                        <select class="form-select" id="statusNewStatus" onchange="syncReceiptDateVisibility()"></select>
                    </div>
                    <div class="col-12" id="receiptDateWrap" style="display:none;">
                        <label class="form-label">收款日期</label>
                        <input type="date" class="form-control" id="receiptDate">
                    </div>
                    <div class="col-12" id="receiptMethodWrap" style="display:none;">
                        <label class="form-label">收款方式</label>
                        <select class="form-select" id="receiptMethod">
                            <?= renderPaymentMethodOptions() ?>
                        </select>
                    </div>
                    <div class="col-12" id="receiptCollectorWrap" style="display:none;">
                        <label class="form-label">收款人</label>
                        <select class="form-select" id="receiptCollector">
                            <option value="">加载中...</option>
                        </select>
                    </div>
                    <div class="col-12" id="receiptCurrencyWrap" style="display:none;">
                        <label class="form-label">收款货币</label>
                        <select class="form-select" id="receiptCurrency">
                            <option value="TWD" selected>TWD（新台币）</option>
                            <option value="CNY">CNY（人民币）</option>
                            <option value="USD">USD（美元）</option>
                        </select>
                    </div>
                    <div class="col-12" id="receiptAmountWrap" style="display:none;">
                        <label class="form-label">实收金额 <span class="text-muted small" id="receiptAmountHint"></span></label>
                        <input type="number" step="0.01" min="0" class="form-control" id="receiptAmount" placeholder="可自定义金额">
                    </div>
                    <div class="col-12" id="receiptVoucherWrap" style="display:none;">
                        <label class="form-label">上传凭证（可拖拽）</label>
                        <div id="voucherDropZone" style="border:2px dashed #ccc;border-radius:8px;padding:20px;text-align:center;cursor:pointer;transition:all 0.3s;">
                            <div id="voucherDropText">拖拽文件到此处或点击上传</div>
                            <input type="file" id="voucherFileInput" class="d-none" multiple accept="image/*,.pdf">
                            <div id="voucherPreview" class="d-flex flex-wrap gap-2 mt-2"></div>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">原因（必填）</label>
                        <input type="text" class="form-control" id="statusReason" maxlength="255" placeholder="请输入调整原因">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" id="btnSubmitStatus">提交</button>
            </div>
        </div>
    </div>
</div>
