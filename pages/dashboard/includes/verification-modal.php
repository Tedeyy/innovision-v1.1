<?php
// This file contains the HTML for the verification modal
// It should be included in your layout or page where the verification is needed
?>

<!-- Verification Modal -->
<div class="modal fade" id="verificationModal" tabindex="-1" aria-labelledby="verificationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="verificationModalLabel">Verify Your Phone Number</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>We've sent a 6-digit verification code to <strong class="phone-number-display"><?php echo htmlspecialchars($_SESSION['verification_phone'] ?? ''); ?></strong>.</p>
                <p>Please enter the code below to verify your phone number.</p>
                
                <!-- OTP Input -->
                <div class="mb-3">
                    <label for="otp_code" class="form-label">Verification Code</label>
                    <input type="text" 
                           class="form-control text-center otp-input" 
                           id="otp_code" 
                           name="otp_code" 
                           maxlength="6" 
                           placeholder="000000" 
                           autocomplete="one-time-code"
                           inputmode="numeric"
                           pattern="\d{6}"
                           required>
                    <div class="form-text">Enter the 6-digit code sent to your phone</div>
                </div>
                
                <!-- Countdown and Resend -->
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div>
                        <span class="text-muted">Code expires in: </span>
                        <span class="countdown fw-bold">5:00</span>
                    </div>
                    <button type="button" class="btn btn-link p-0 resend-otp" disabled>
                        Resend Code
                    </button>
                </div>
                
                <!-- Messages -->
                <div class="modal-messages mt-3">
                    <div class="modal-error d-none text-danger"></div>
                    <div class="modal-success d-none text-success"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary submit-otp">Verify</button>
            </div>
        </div>
    </div>
</div>

<!-- Include the JavaScript -->
<script src="/pages/dashboard/assets/js/contact-verification.js"></script>

<!-- Initialize the verification system -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize contact verification
    const contactVerification = new ContactVerification({
        userId: '<?php echo $_SESSION['user_id'] ?? ''; ?>',
        userRole: '<?php echo $_SESSION['user_role'] ?? ''; ?>',
        // Add any additional options here
    });
});
</script>
