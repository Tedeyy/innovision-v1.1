// Contact Verification Script for Buyer Profile
let countdownInterval;
let timeLeft = 300; // 5 minutes in seconds
let verificationInProgress = false;
let currentContact = '';

// DOM Elements
let verificationModal;
let contactInput;
let verifyBtn;
let contactMessage;
let contactNumberDisplay;
let otpInput;
let otpMessage;
let timeElement;
let submitOtpBtn;
let resendOtpBtn;

document.addEventListener('DOMContentLoaded', function() {
    // Initialize modal
    verificationModal = new bootstrap.Modal(document.getElementById('verificationModal'));
    contactInput = document.getElementById('contact');
    verifyBtn = document.getElementById('verifyBtn');
    contactMessage = document.getElementById('contactMessage');
    contactNumberDisplay = document.getElementById('contactNumberDisplay');
    otpInput = document.getElementById('otp');
    otpMessage = document.getElementById('otpMessage');
    timeElement = document.getElementById('time');
    submitOtpBtn = document.getElementById('submitOtpBtn');
    resendOtpBtn = document.getElementById('resendOtp');

    // Event listeners
    if (verifyBtn) {
        verifyBtn.addEventListener('click', initiateVerification);
    }
    
    if (submitOtpBtn) submitOtpBtn.addEventListener('click', submitOtp);
    if (resendOtpBtn) {
        resendOtpBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!verificationInProgress) {
                initiateVerification(true);
            }
        });
    }

    // Handle form submission to prevent submitting if contact is not verified
    const profileForm = document.getElementById('profileForm');
    if (profileForm) {
        profileForm.addEventListener('submit', function(e) {
            if (contactInput && contactInput.value && !contactInput.readOnly && !document.querySelector('.verification-status.verified')) {
                e.preventDefault();
                showMessage('Please verify your contact number before saving.', 'error');
            }
        });
    }
});

// Function to initiate verification
function initiateVerification(isResend = false) {
    const contact = contactInput.value.trim();
    
    // Validate contact number
    if (!contact) {
        showMessage('Please enter a contact number.', 'error');
        return;
    }
    
    if (contact.length !== 11) {
        showMessage('Contact number must be 11 digits.', 'error');
        return;
    }

    // Check if contact is already in use by another user
    $.ajax({
        url: '../../../includes/check_contact.php',
        type: 'POST',
        data: { 
            contact: contact,
            current_user_id: document.body.getAttribute('data-user-id')
        },
        dataType: 'json',
        beforeSend: function() {
            verificationInProgress = true;
            if (verifyBtn) verifyBtn.disabled = true;
            showMessage('Checking contact number...', 'info');
        },
        success: function(response) {
            if (response.available) {
                // Contact is available, proceed with verification
                currentContact = contact;
                sendOtp(contact, isResend);
            } else {
                showMessage('This contact number is already in use by another account.', 'error');
                verificationInProgress = false;
                if (verifyBtn) verifyBtn.disabled = false;
            }
        },
        error: function() {
            showMessage('Error checking contact number. Please try again.', 'error');
            verificationInProgress = false;
            if (verifyBtn) verifyBtn.disabled = false;
        }
    });
}

// Function to send OTP
function sendOtp(contact, isResend = false) {
    showMessage('Sending verification code...', 'info');
    
    $.ajax({
        url: '../../../includes/send_otp.php',
        type: 'POST',
        data: { 
            contact: contact,
            user_id: document.body.getAttribute('data-user-id'),
            user_role: 'buyer',
            is_resend: isResend ? 1 : 0
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showMessage('Verification code sent!', 'success');
                // Show modal
                contactNumberDisplay.textContent = contact;
                otpInput.value = '';
                otpMessage.textContent = '';
                otpMessage.className = 'verification-message';
                verificationModal.show();
                
                // Start countdown
                startCountdown();
            } else {
                showMessage(response.message || 'Failed to send verification code. Please try again.', 'error');
            }
            verificationInProgress = false;
            if (verifyBtn) verifyBtn.disabled = false;
        },
        error: function() {
            showMessage('Error sending verification code. Please try again.', 'error');
            verificationInProgress = false;
            if (verifyBtn) verifyBtn.disabled = false;
        }
    });
}

// Function to submit OTP
function submitOtp() {
    const otp = otpInput.value.trim();
    
    if (!otp || otp.length !== 6) {
        otpMessage.textContent = 'Please enter a valid 6-digit code.';
        otpMessage.className = 'verification-message error';
        return;
    }
    
    submitOtpBtn.disabled = true;
    submitOtpBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Verifying...';
    
    $.ajax({
        url: '../../../includes/verify_otp.php',
        type: 'POST',
        data: { 
            contact: currentContact,
            otp: otp,
            user_id: document.body.getAttribute('data-user-id'),
            user_role: 'buyer'
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Update UI
                contactInput.value = currentContact;
                contactInput.readOnly = true;
                
                // Hide verify button and show verified status
                if (verifyBtn) verifyBtn.style.display = 'none';
                const verifiedStatus = document.createElement('span');
                verifiedStatus.className = 'verification-status verified';
                verifiedStatus.innerHTML = '<i class="fas fa-check-circle"></i> Verified';
                contactInput.parentNode.appendChild(verifiedStatus);
                
                // Show success message
                showMessage('Contact number verified successfully!', 'success');
                
                // Close modal
                verificationModal.hide();
                
                // Clear countdown
                clearInterval(countdownInterval);
                
                // Submit the form to save the verified contact
                document.getElementById('profileForm').submit();
            } else {
                otpMessage.textContent = response.message || 'Invalid verification code. Please try again.';
                otpMessage.className = 'verification-message error';
                submitOtpBtn.disabled = false;
                submitOtpBtn.textContent = 'Verify';
            }
        },
        error: function() {
            otpMessage.textContent = 'Error verifying code. Please try again.';
            otpMessage.className = 'verification-message error';
            submitOtpBtn.disabled = false;
            submitOtpBtn.textContent = 'Verify';
        }
    });
}

// Function to start countdown
function startCountdown() {
    timeLeft = 300; // Reset to 5 minutes
    updateCountdown();
    clearInterval(countdownInterval);
    countdownInterval = setInterval(updateCountdown, 1000);
}

// Function to update countdown
function updateCountdown() {
    const minutes = Math.floor(timeLeft / 60);
    const seconds = timeLeft % 60;
    timeElement.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
    
    if (timeLeft <= 0) {
        clearInterval(countdownInterval);
        timeElement.textContent = '00:00';
        otpMessage.textContent = 'Verification code has expired.';
        otpMessage.className = 'verification-message error';
    } else {
        timeLeft--;
    }
}

// Function to show messages
function showMessage(message, type = 'info') {
    if (!contactMessage) return;
    contactMessage.textContent = message;
    contactMessage.className = 'verification-message ' + type;
    contactMessage.style.display = 'block';
}

// Close modal event
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('verificationModal');
    if (modal) {
        modal.addEventListener('hidden.bs.modal', function () {
            clearInterval(countdownInterval);
            if (otpInput) otpInput.value = '';
            if (otpMessage) {
                otpMessage.textContent = '';
                otpMessage.className = 'verification-message';
            }
            if (submitOtpBtn) {
                submitOtpBtn.disabled = false;
                submitOtpBtn.textContent = 'Verify';
            }
            
            // Reset the form if verification was not completed
            if (contactInput && !document.querySelector('.verification-status.verified')) {
                contactInput.value = '';
                if (verifyBtn) {
                    verifyBtn.disabled = false;
                    verifyBtn.style.display = 'inline-block';
                }
            }
        });
    }
});
