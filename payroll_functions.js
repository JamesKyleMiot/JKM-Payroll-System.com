// JavaScript functions for payroll management

function editPayroll(payrollData) {
    // Populate the edit modal with payroll data
    document.getElementById('editPayrollId').value = payrollData.id || '';
    document.getElementById('editPayrollEmployee').value = payrollData.employee_id || '';
    document.getElementById('editPayrollStartDate').value = payrollData.pay_period_start || payrollData.start_date || '';
    document.getElementById('editPayrollEndDate').value = payrollData.pay_period_end || payrollData.end_date || '';
    document.getElementById('editPayrollRegularHours').value = payrollData.regular_hours || 0;
    document.getElementById('editPayrollOvertimeHours').value = payrollData.overtime_hours || 0;
    document.getElementById('editPayrollGrossPay').value = payrollData.gross_pay || 0;
    document.getElementById('editPayrollTaxes').value = payrollData.taxes || 0;
    document.getElementById('editPayrollDeductions').value = payrollData.deductions || 0;
    document.getElementById('editPayrollNetPay').value = payrollData.net_pay || 0;
    document.getElementById('editPayrollStatus').value = payrollData.status || 'draft';
    document.getElementById('editPayrollNotes').value = payrollData.notes || '';
    
    // Show the modal
    document.getElementById('editPayrollModal').style.display = 'block';
}

function generatePayrollForMonth() {
    // Show confirmation dialog
    if (confirm('Generate payroll records for all active employees for this month? This will create draft payroll entries based on time entries.')) {
        // Submit form to generate payroll
        const form = document.createElement('form');
        form.method = 'post';
        form.action = 'index.php?page=payroll';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'start_pay_run';
        const monthInput = document.createElement('input');
        monthInput.type = 'hidden';
        monthInput.name = 'month';
        monthInput.value = new Date().toISOString().slice(0,7);
        
        form.appendChild(actionInput);
        form.appendChild(monthInput);
        document.body.appendChild(form);
        form.submit();
    }
}

// Auto-calculate payroll values when editing
document.addEventListener('DOMContentLoaded', function() {
    const regularHoursInput = document.getElementById('editPayrollRegularHours');
    const overtimeHoursInput = document.getElementById('editPayrollOvertimeHours');
    const grossPayInput = document.getElementById('editPayrollGrossPay');
    const taxesInput = document.getElementById('editPayrollTaxes');
    const deductionsInput = document.getElementById('editPayrollDeductions');
    const netPayInput = document.getElementById('editPayrollNetPay');
    
    function calculatePayroll() {
        const regularHours = parseFloat(regularHoursInput?.value || 0);
        const overtimeHours = parseFloat(overtimeHoursInput?.value || 0);
        const grossPay = parseFloat(grossPayInput?.value || 0);
        
        if (grossPay > 0) {
            // Calculate taxes (10%)
            const taxes = grossPay * 0.10;
            taxesInput.value = taxes.toFixed(2);
            
            // Calculate deductions (SSS 4.5% + PhilHealth 2.5% + Pag-IBIG 2%)
            const deductions = (grossPay * 0.045) + (grossPay * 0.025) + (grossPay * 0.02);
            deductionsInput.value = deductions.toFixed(2);
            
            // Calculate net pay
            const netPay = grossPay - taxes - deductions;
            netPayInput.value = netPay.toFixed(2);
        }
    }
    
    // Add event listeners if elements exist
    if (grossPayInput) {
        grossPayInput.addEventListener('input', calculatePayroll);
    }
});

// Close modal when clicking outside
window.addEventListener('click', function(event) {
    const modal = document.getElementById('editPayrollModal');
    if (event.target === modal) {
        modal.style.display = 'none';
    }
});

// Current viewed payroll data holder
let currentViewPayroll = null;

function viewPayroll(payrollData) {
    currentViewPayroll = payrollData || {};
    document.getElementById('viewPayrollEmployee').textContent = payrollData.employee_name || payrollData.name || '—';
    const start = payrollData.pay_period_start || payrollData.start_date || '';
    const end = payrollData.pay_period_end || payrollData.end_date || '';
    document.getElementById('viewPayrollPeriod').textContent = start && end ? (new Date(start).toLocaleDateString() + ' — ' + new Date(end).toLocaleDateString()) : (start || end || '—');
    document.getElementById('viewPayrollRegularHours').textContent = (payrollData.regular_hours || 0).toFixed ? (parseFloat(payrollData.regular_hours || 0).toFixed(2) + ' h') : payrollData.regular_hours || '0.00';
    document.getElementById('viewPayrollOvertimeHours').textContent = (payrollData.overtime_hours || 0).toFixed ? (parseFloat(payrollData.overtime_hours || 0).toFixed(2) + ' h') : payrollData.overtime_hours || '0.00';
    document.getElementById('viewPayrollGrossPay').textContent = '₱' + (parseFloat(payrollData.gross_pay || 0).toFixed(2));
    document.getElementById('viewPayrollTaxes').textContent = '₱' + (parseFloat(payrollData.taxes || 0).toFixed(2));
    document.getElementById('viewPayrollDeductions').textContent = '₱' + (parseFloat(payrollData.deductions || 0).toFixed(2));
    document.getElementById('viewPayrollNetPay').textContent = '₱' + (parseFloat(payrollData.net_pay || 0).toFixed(2));
    document.getElementById('viewPayrollStatus').textContent = payrollData.status ? payrollData.status.charAt(0).toUpperCase() + payrollData.status.slice(1) : '—';
    document.getElementById('viewPayrollNotes').textContent = payrollData.notes || '-';

    document.getElementById('viewPayrollModal').style.display = 'flex';
}

function closeViewPayroll() {
    document.getElementById('viewPayrollModal').style.display = 'none';
}

// Close fullscreen modal when clicking outside card
window.addEventListener('click', function(event) {
    const vm = document.getElementById('viewPayrollModal');
    const card = document.querySelector('.fullscreen-card');
    if (vm && card && !card.contains(event.target) && event.target === vm) {
        closeViewPayroll();
    }
});

// Analytics Charts
document.addEventListener('DOMContentLoaded', function() {
    // Initialize charts if on analytics page
    if (window.location.search.includes('page=analytics')) {
        initializeAnalyticsCharts();
    }
});

function initializeAnalyticsCharts() {
    // Monthly Payroll Bar Chart
    const monthlyCtx = document.getElementById('monthlyPayrollChart');
    if (monthlyCtx) {
        new Chart(monthlyCtx, {
            type: 'bar',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                datasets: [{
                    data: [38.1, 57.5, 28.5, -10.0, 27.9, 11.0, 6.2, 5.0, -24.5, 117.4, -40.0, -0.5],
                    backgroundColor: '#a855f7',
                    borderRadius: 8,
                    maxBarThickness: 40
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { display: false },
                        ticks: { callback: value => value + '%' }
                    },
                    x: {
                        grid: { display: false }
                    }
                }
            }
        });
    }

    // Payroll Breakdown Pie Chart
    const breakdownCtx = document.getElementById('payrollBreakdownChart');
    if (breakdownCtx) {
        new Chart(breakdownCtx, {
            type: 'doughnut',
            data: {
                labels: ['Salary', 'Over Time', 'Business T...', 'Benefit'],
                datasets: [{
                    data: [80, 14, 3, 3],
                    backgroundColor: ['#10b981', '#06b6d4', '#f59e0b', '#ef4444'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { usePointStyle: true, padding: 20 }
                    }
                }
            }
        });
    }

    // Contract Type Pie Chart
    const contractCtx = document.getElementById('contractTypeChart');
    if (contractCtx) {
        new Chart(contractCtx, {
            type: 'doughnut',
            data: {
                labels: ['Full-time', 'Part-Time'],
                datasets: [{
                    data: [95.56, 4.44],
                    backgroundColor: ['#06b6d4', '#f59e0b'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { usePointStyle: true, padding: 20 }
                    }
                }
            }
        });
    }
}