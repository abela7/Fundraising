// Grid Management Page JavaScript

document.addEventListener('DOMContentLoaded', function() {
    const cells = document.querySelectorAll('.grid-cell-cell');
    const cellDetailModal = new bootstrap.Modal(document.getElementById('cellDetailModal'));
    const unallocateModal = new bootstrap.Modal(document.getElementById('unallocateModal'));
    const allocateModal = new bootstrap.Modal(document.getElementById('allocateModal'));
    
    // Handle cell clicks
    cells.forEach(cell => {
        cell.addEventListener('click', function() {
            const cellId = this.dataset.cellId;
            const status = this.dataset.status;
            const pledgeId = this.dataset.pledgeId || '';
            const paymentId = this.dataset.paymentId || '';
            const batchId = this.dataset.batchId || '';
            const donorName = this.dataset.donorName || '';
            const amount = this.dataset.amount || '0';
            const cellType = this.dataset.cellType || '';
            const areaSize = this.dataset.areaSize || '0';
            
            showCellDetails(cellId, status, pledgeId, paymentId, batchId, donorName, amount, cellType, areaSize);
        });
        
        // Add keyboard support
        cell.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                this.click();
            }
        });
    });
    
    function showCellDetails(cellId, status, pledgeId, paymentId, batchId, donorName, amount, cellType, areaSize) {
        const modal = document.getElementById('cellDetailModal');
        const content = document.getElementById('cellDetailsContent');
        const title = document.getElementById('modalCellId');
        
        title.textContent = cellId;
        
        let html = `
            <div class="detail-section">
                <h6>Cell Information</h6>
                <div class="detail-row">
                    <span class="detail-label">Cell ID:</span>
                    <span class="detail-value">${cellId}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Type:</span>
                    <span class="detail-value">${cellType} (${areaSize} m²)</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Status:</span>
                    <span class="detail-value">
                        <span class="badge bg-${getStatusColor(status)}">${status.charAt(0).toUpperCase() + status.slice(1)}</span>
                    </span>
                </div>
            </div>
        `;
        
        if (status !== 'available') {
            html += `
                <div class="detail-section">
                    <h6>Allocation Details</h6>
                    ${donorName ? `
                    <div class="detail-row">
                        <span class="detail-label">Donor:</span>
                        <span class="detail-value">${donorName}</span>
                    </div>
                    ` : ''}
                    ${amount && parseFloat(amount) > 0 ? `
                    <div class="detail-row">
                        <span class="detail-label">Amount:</span>
                        <span class="detail-value">£${parseFloat(amount).toFixed(2)}</span>
                    </div>
                    ` : ''}
                    ${pledgeId ? `
                    <div class="detail-row">
                        <span class="detail-label">Pledge ID:</span>
                        <span class="detail-value">#${pledgeId}</span>
                    </div>
                    ` : ''}
                    ${paymentId ? `
                    <div class="detail-row">
                        <span class="detail-label">Payment ID:</span>
                        <span class="detail-value">#${paymentId}</span>
                    </div>
                    ` : ''}
                    ${batchId ? `
                    <div class="detail-row">
                        <span class="detail-label">Batch ID:</span>
                        <span class="detail-value">#${batchId}</span>
                    </div>
                    ` : ''}
                </div>
            `;
        }
        
        html += `
            <div class="cell-actions">
        `;
        
        if (status === 'available') {
            html += `
                <button type="button" class="btn btn-success" onclick="openAllocateModal('${cellId}')">
                    <i class="fas fa-link me-2"></i>Allocate Cell
                </button>
            `;
        } else {
            html += `
                <button type="button" class="btn btn-warning" onclick="openUnallocateModal('${cellId}')">
                    <i class="fas fa-unlink me-2"></i>Unallocate Cell
                </button>
            `;
        }
        
        html += `
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Close
                </button>
            </div>
        `;
        
        content.innerHTML = html;
        cellDetailModal.show();
    }
    
    function getStatusColor(status) {
        const colors = {
            'available': 'success',
            'pledged': 'warning',
            'paid': 'primary',
            'blocked': 'danger'
        };
        return colors[status] || 'secondary';
    }
    
    // Make functions globally available
    window.openUnallocateModal = function(cellId) {
        document.getElementById('unallocateCellId').value = cellId;
        document.getElementById('unallocateCellIdDisplay').textContent = cellId;
        cellDetailModal.hide();
        setTimeout(() => unallocateModal.show(), 300);
    };
    
    window.openAllocateModal = function(cellId) {
        document.getElementById('allocateCellId').value = cellId;
        document.getElementById('allocateDonorName').value = '';
        document.getElementById('allocateAmount').value = '';
        document.getElementById('allocateStatus').value = 'pledged';
        document.getElementById('allocatePledgeId').value = '';
        document.getElementById('allocatePaymentId').value = '';
        cellDetailModal.hide();
        setTimeout(() => allocateModal.show(), 300);
    };
});

