(function(){
    var form = document.getElementById('seller-reg-form');
    var contact = document.getElementById('contact');
    var validId = document.getElementById('valid_id');
    var desiredFile = document.getElementById('desired_id_filename');
    var firstname = document.getElementById('firstname');
    var lastname = document.getElementById('lastname');
    var togglePwd = document.getElementById('toggle-password');
    var pwd = document.getElementById('password');
    var cpwd = document.getElementById('confirm_password');
    var pwdErr = document.getElementById('password_error');
    var confirmed = document.getElementById('confirmed');
    var modal = document.getElementById('confirm_modal');
    var confirmContent = document.getElementById('confirm_content');
    var confirmBtn = document.getElementById('confirm_btn');
    var editBtn = document.getElementById('edit_btn');
    var imagePreview = document.getElementById('image_preview_confirm');
    var fileError = document.getElementById('file_error');
    var supdoctype = document.getElementById('supdoctype');
    var currentObjectUrl = null;

    function sanitizeName(s){
        return (s||'').toString().trim().toLowerCase().replace(/[^a-z0-9]+/g,'_').replace(/^_+|_+$/g,'');
    }

    contact.addEventListener('input', function(e){
        var digits = this.value.replace(/\D/g, '');
        if (digits.length > 11) digits = digits.slice(0,11);
        this.value = digits;
    });

    togglePwd.addEventListener('click', function(){
        if (pwd.type === 'password') {
            pwd.type = 'text';
            cpwd.type = 'text';
            this.textContent = 'Hide';
        } else {
            pwd.type = 'password';
            cpwd.type = 'password';
            this.textContent = 'Show';
        }
    });

    function updateDesiredFilename(){
        var f = sanitizeName(firstname.value);
        var l = sanitizeName(lastname.value);
        var base = (f && l) ? (f + '_' + l + '_id') : '';
        var ext = '';
        if (validId.files && validId.files[0] && validId.files[0].name) {
            var name = validId.files[0].name;
            var i = name.lastIndexOf('.');
            if (i > -1) ext = name.slice(i);
        }
        desiredFile.value = base ? (base + ext) : '';
    }

    firstname.addEventListener('input', updateDesiredFilename);
    lastname.addEventListener('input', updateDesiredFilename);
    validId.addEventListener('change', function(){
        fileError.style.display = 'none';
        if (validId.files && validId.files[0]){
            var f = validId.files[0];
            var max = 20 * 1024 * 1024;
            if (f.size > max){
                fileError.style.display = 'block';
                validId.value = '';
                if (currentObjectUrl){ URL.revokeObjectURL(currentObjectUrl); currentObjectUrl = null; }
                imagePreview.style.display = 'none';
                imagePreview.innerHTML = '';
                desiredFile.value = '';
                return;
            }
        }
        updateDesiredFilename();
    });

    function passwordsMatch(){
        return pwd.value === cpwd.value;
    }

    function buildPreview(){
        var fields = [
            ['First Name', document.getElementById('firstname').value],
            ['Middle Name', document.getElementById('middlename').value],
            ['Last Name', document.getElementById('lastname').value],
            ['Birthdate', document.getElementById('bdate').value],
            ['Contact Number', document.getElementById('contact').value],
            ['Email', document.getElementById('email').value],
            ['RSBSA Number', document.getElementById('rsbsanum').value],
            ['Address', document.getElementById('address').value],
            ['Barangay', document.getElementById('barangay').value],
            ['Municipality', document.getElementById('municipality').value],
            ['Province', document.getElementById('province').value],
            ['Document Type', supdoctype ? supdoctype.value : ''],
            ['Valid ID Filename', desiredFile.value || (validId.files[0] ? validId.files[0].name : '')],
            ['Valid ID Number', document.getElementById('idnum').value],
            ['Username', document.getElementById('username').value]
        ];
        var html = '';
        for (var i=0;i<fields.length;i++){
            html += '<div><strong>' + fields[i][0] + ':</strong><br>' + (fields[i][1] || '') + '</div>';
        }
        confirmContent.innerHTML = html;
        if (validId.files && validId.files[0]){
            if (currentObjectUrl){ URL.revokeObjectURL(currentObjectUrl); currentObjectUrl = null; }
            currentObjectUrl = URL.createObjectURL(validId.files[0]);
            imagePreview.innerHTML = '<div><strong>Valid ID Preview:</strong></div>'+
                '<img src="' + currentObjectUrl + '" alt="Valid ID Preview" style="max-width:100%; height:auto; border:1px solid #000; margin-top:8px;" />';
            imagePreview.style.display = 'block';
        } else {
            imagePreview.style.display = 'none';
            imagePreview.innerHTML = '';
        }
    }

    form.addEventListener('submit', function(e){
        if (confirmed.value === '1') return;
        e.preventDefault();
        if (!passwordsMatch()){
            pwdErr.style.display = 'block';
            cpwd.focus();
            return;
        } else {
            pwdErr.style.display = 'none';
        }
        if (contact.value.length !== 11){
            contact.focus();
            return;
        }
        updateDesiredFilename();
        if (!desiredFile.value){
            updateDesiredFilename();
        }
        if (!form.checkValidity()){
            form.reportValidity();
            return;
        }
        buildPreview();
        modal.style.display = 'flex';
    });

    editBtn.addEventListener('click', function(){
        modal.style.display = 'none';
        if (currentObjectUrl){ URL.revokeObjectURL(currentObjectUrl); currentObjectUrl = null; }
    });
    confirmBtn.addEventListener('click', function(){
        confirmed.value = '1';
        modal.style.display = 'none';
        if (currentObjectUrl){ URL.revokeObjectURL(currentObjectUrl); currentObjectUrl = null; }
        form.submit();
    });
})();
