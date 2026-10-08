// ajax helper with centralized dispatcher
function ajaxCall(action, data, onSuccess, onError) {
    data = data || {};
    data.action = action;
    $.post('ajax.php', data, function(response) {
        if (response) {
            if (response.status === 'success') {
                if (onSuccess) onSuccess(response);
            } else {
                if (onError) onError(response.message);
                else alert('Error: ' + response.message);
            }
        } else {
            if (onError) onError('Empty response');
        }
    }, 'json').fail(function() {
        if (onError) onError('Server communication error');
    });
}

$(document).ready(function() {
    // Load roles dynamically for both add and edit modals
    function loadRolesIntoSelects() {
        $.get('users_roles.php', function(roles) {
            var options = roles.map(r => `<option value="${r}">${r.charAt(0).toUpperCase() + r.slice(1)}</option>`).join('');
            $('#addUserRoleSelect, #editUserRole').html(options);
        }, 'json');
    }
   // loadRolesIntoSelects();

    // Add user form submit
    $('#addUserForm').submit(function(e) {
        e.preventDefault();
        var form = $(this);
        var btn = form.find('button[type=submit]');
        btn.prop('disabled', true);
        $('#addUserResult').text('');

        ajaxCall('add_user', form.serializeArray(), function(response) {
            $('#addUserResult').html('<div class="alert alert-success">' + response.message + '</div>');
            setTimeout(() => location.reload(), 1000);
        }, function(errorMsg) {
            $('#addUserResult').html('<div class="alert alert-danger">' + errorMsg + '</div>');
            btn.prop('disabled', false);
        });
    });

    // Delete user button
    $('.btn-delete-user').click(function() {
        if (!confirm('Are you sure you want to delete this user?')) return;
        var row = $(this).closest('tr');
        var userId = row.data('user-id');
        ajaxCall('delete_user', {user_id: userId}, function() {
            row.remove();
        }, function(errorMsg) {
            alert('Error: ' + errorMsg);
        });
    });

    // Show edit modal and fill data
    $('.btn-edit-user').click(function() {
        var row = $(this).closest('tr');
        var userId = row.data('user-id');
        var email = row.find('td:nth-child(3)').text().trim();
        var role = row.data('user-role') || 'subscriber';

        $('#editUserId').val(userId);
        $('#editUserEmail').val(email);
        $('#editUserRole').val(role);
        $('#editUserResult').html('');
        $('#editUserModal').modal('show');
    });

    // Edit user form submit
    $('#editUserForm').submit(function(e) {
        e.preventDefault();
        var form = $(this);
        var btn = form.find('button[type=submit]');
        btn.prop('disabled', true);
        $('#editUserResult').text('');

        ajaxCall('update_user', form.serializeArray(), function(response) {
            $('#editUserResult').html('<div class="alert alert-success">' + response.message + '</div>');
            setTimeout(() => location.reload(), 1000);
        }, function(errorMsg) {
            $('#editUserResult').html('<div class="alert alert-danger">' + errorMsg + '</div>');
            btn.prop('disabled', false);
        });
    });
});
