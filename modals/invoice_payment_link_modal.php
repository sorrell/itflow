<div class="modal" id="invoicePaymentLinkModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content bg-dark">
      <div class="modal-header">
        <h5 class="modal-title text-white"><i class="fas fa-fw fa-credit-card mr-2"></i>Payment Link (Remittance)</h5>
        <button type="button" class="close text-white" data-dismiss="modal">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <form action="post.php" method="post" autocomplete="off">
        <input type="hidden" name="invoice_id" value="<?php echo $invoice_id; ?>">
        <div class="modal-body bg-white">  
          <div class="form-group">
            <label>Payment Link URL</label>
            <input type="url" class="form-control" name="payment_link" placeholder="Enter payment link URL (e.g., https://example.com/pay/invoice123)" value="<?php echo $invoice_payment_link; ?>">
            <small class="form-text text-muted">This will be displayed as a "Pay Invoice" button for clients to make payments</small>
          </div>
        </div>
        <div class="modal-footer bg-white">
          <button type="submit" name="invoice_payment_link" class="btn btn-primary text-bold"><i class="fas fa-check mr-2"></i>Save</button>
          <button type="button" class="btn btn-light" data-dismiss="modal"><i class="fas fa-times mr-2"></i>Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div> 