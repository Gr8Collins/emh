<?php
// This file contains all the modals used across the website
// Include this file in pages that need modals
?>

<!-- Login Modal -->
<div class="modal fade" id="loginModal" tabindex="-1" aria-labelledby="loginModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="loginModalLabel">Login to Your Account</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="loginEmail" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="loginEmail" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label for="loginPassword" class="form-label">Password</label>
                        <input type="password" class="form-control" id="loginPassword" name="password" required>
                    </div>
                    <div class="d-grid">
                        <button type="submit" name="login" class="btn btn-primary">Login</button>
                    </div>
                    <div class="text-center mt-3">
                        <p>Don't have an account? <a href="#" onclick="switchToSignup()">Register here</a></p>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Registration Modal -->
<div class="modal fade" id="signupModal" tabindex="-1" aria-labelledby="signupModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="signupModalLabel">Create Account</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Username *</label>
                        <input type="text" class="form-control" name="username" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email *</label>
                        <input type="email" class="form-control" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password *</label>
                        <input type="password" class="form-control" name="password" required>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">First Name</label>
                            <input type="text" class="form-control" name="first_name">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Last Name</label>
                            <input type="text" class="form-control" name="last_name">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input type="text" class="form-control" name="phone">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Account Type</label>
                        <select class="form-select" name="role">
                            <option value="user" selected>User</option>
                            <option value="seller">Seller</option>
                            <option value="buyer">Buyer</option>
                            <option value="employer">Employer</option>
                            <option value="job_seeker">Job Seeker</option>
                        </select>
                    </div>
                    <div class="d-grid">
                        <button type="submit" name="signup" class="btn btn-success">Create Account</button>
                    </div>
                    <div class="text-center mt-3">
                        <p>Already have an account? <a href="#" onclick="switchToLogin()">Login here</a></p>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Post Job Modal -->
<div class="modal fade" id="postJobModal" tabindex="-1" aria-labelledby="postJobModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content rounded-4 shadow">
            <div class="modal-header">
                <h5 class="modal-title" id="postJobModalLabel">Post a Job</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="jobpost.php" id="jobPostForm">
                <input type="hidden" name="category" value="job">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Job Title *</label>
                            <input type="text" class="form-control" name="title" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Company *</label>
                            <input type="text" class="form-control" name="company" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description *</label>
                            <textarea class="form-control" name="description" rows="3" required></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Location *</label>
                            <input type="text" class="form-control" name="location" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Job Type</label>
                            <select class="form-select" name="job_type">
                                <option value="full-time">Full-Time</option>
                                <option value="part-time">Part-Time</option>
                                <option value="contract">Contract</option>
                                <option value="freelance">Freelance</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Salary</label>
                            <input type="number" class="form-control" name="salary">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Application Email *</label>
                            <input type="email" class="form-control" name="application_email" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Contact Name</label>
                            <input type="text" class="form-control" name="contact_name">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Contact Phone</label>
                            <input type="text" class="form-control" name="contact_phone">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Contact Email</label>
                            <input type="email" class="form-control" name="contact_email">
                        </div>
                        <?php if (isset($_SESSION['user_id'])): ?>
                            <input type="hidden" name="user_id" value="<?php echo $_SESSION['user_id']; ?>">
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" name="post_job" class="btn btn-success">Post Job</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Post Property Modal -->
<div class="modal fade" id="postPropertyModal" tabindex="-1" aria-labelledby="postPropertyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="postPropertyModalLabel">Post New Property</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="listproperties.php" method="POST" enctype="multipart/form-data" id="propertyPostForm">
                <input type="hidden" name="category" value="property">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Title *</label>
                        <input type="text" name="title" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description *</label>
                        <textarea name="description" class="form-control" rows="3" required></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Price</label>
                            <input type="number" step="0.01" name="price" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Location *</label>
                            <input type="text" name="location" class="form-control" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Bedrooms</label>
                            <input type="number" name="bedrooms" class="form-control">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Bathrooms</label>
                            <input type="number" name="bathrooms" class="form-control">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Area (sq.m)</label>
                            <input type="number" step="0.01" name="area" class="form-control">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Type</label>
                            <select name="type" class="form-select">
                                <option value="sale">For Sale</option>
                                <option value="rent">For Rent</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Condition</label>
                            <select name="condition" class="form-select">
                                <option value="new">New</option>
                                <option value="used">Used</option>
                                <option value="refurbished">Refurbished</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Upload Image</label>
                        <input type="file" name="image" class="form-control">
                    </div>
                    <h6 class="mt-4">Contact Information</h6>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <input type="text" name="contact_name" class="form-control" placeholder="Contact Name" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <input type="text" name="contact_phone" class="form-control" placeholder="Phone">
                        </div>
                        <div class="col-md-4 mb-3">
                            <input type="email" name="contact_email" class="form-control" placeholder="Email">
                        </div>
                    </div>
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <input type="hidden" name="user_id" value="<?php echo $_SESSION['user_id']; ?>">
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-success">Save Listing</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function switchToLogin() {
    const signupModal = bootstrap.Modal.getInstance(document.getElementById('signupModal'));
    if (signupModal) {
        signupModal.hide();
    }
    
    const loginModal = new bootstrap.Modal(document.getElementById('loginModal'));
    loginModal.show();
}

function switchToSignup() {
    const loginModal = bootstrap.Modal.getInstance(document.getElementById('loginModal'));
    if (loginModal) {
        loginModal.hide();
    }
    
    const signupModal = new bootstrap.Modal(document.getElementById('signupModal'));
    signupModal.show();
}
</script>