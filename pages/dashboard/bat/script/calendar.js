// Calendar functionality
document.addEventListener('DOMContentLoaded', function() {
    const calEl = document.getElementById('calendar');
    if (!calEl) return;
    
    // Initialize FullCalendar
    const calendar = new FullCalendar.Calendar(calEl, {
        initialView: 'dayGridMonth',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay'
        },
        events: function(fetchInfo, successCallback, failureCallback) {
            // Fetch events from the server
            fetch('get_events.php')
                .then(response => response.json())
                .then(data => {
                    const events = data.map(event => ({
                        id: event.id,
                        title: event.title,
                        start: event.start_date,
                        end: event.end_date || null,
                        allDay: event.all_day || false,
                        color: event.color || '#4a90e2',
                        extendedProps: {
                            description: event.description || '',
                            location: event.location || '',
                            type: event.type || 'general'
                        }
                    }));
                    successCallback(events);
                })
                .catch(error => {
                    console.error('Error loading events:', error);
                    failureCallback(error);
                });
        },
        eventClick: function(info) {
            // Handle event click
            window.selectedEvent = info.event;
            
            // Update modal content
            const modal = new bootstrap.Modal(document.getElementById('eventModal'));
            const modalTitle = document.getElementById('eventModalLabel');
            const modalBody = document.getElementById('eventModalBody');
            
            if (modalTitle && modalBody) {
                modalTitle.textContent = info.event.title;
                
                let content = '';
                if (info.event.extendedProps.description) {
                    content += `<p>${info.event.extendedProps.description}</p>`;
                }
                if (info.event.extendedProps.location) {
                    content += `<p><strong>Location:</strong> ${info.event.extendedProps.location}</p>`;
                }
                content += `<p><strong>Start:</strong> ${info.event.start?.toLocaleString()}</p>`;
                if (info.event.end) {
                    content += `<p><strong>End:</strong> ${info.event.end.toLocaleString()}</p>`;
                }
                
                modalBody.innerHTML = content;
                modal.show();
            }
        },
        dateClick: function(info) {
            // Handle date click (for adding new events)
            const modal = new bootstrap.Modal(document.getElementById('addEventModal'));
            const dateInput = document.getElementById('eventDate');
            
            if (dateInput) {
                dateInput.value = info.dateStr;
                modal.show();
            }
        },
        editable: true,
        selectable: true,
        selectMirror: true,
        dayMaxEvents: true,
        height: 'auto',
        nowIndicator: true,
        firstDay: 1 // Start week on Monday
    });
    
    // Render the calendar
    calendar.render();
    
    // Handle form submission for adding new events
    const addEventForm = document.getElementById('addEventForm');
    if (addEventForm) {
        addEventForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(addEventForm);
            const submitBtn = addEventForm.querySelector('[type="submit"]');
            const originalBtnText = submitBtn.innerHTML;
            
            try {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...';
                
                const response = await fetch('add_event.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Refresh calendar
                    calendar.refetchEvents();
                    // Close modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('addEventModal'));
                    if (modal) modal.hide();
                    // Reset form
                    addEventForm.reset();
                    // Show success message
                    alert('Event added successfully!');
                } else {
                    throw new Error(result.message || 'Failed to add event');
                }
            } catch (error) {
                console.error('Error adding event:', error);
                alert('Error: ' + (error.message || 'Failed to add event'));
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnText;
            }
        });
    }
    
    // Handle edit and delete buttons
    const btnEdit = document.getElementById('btnEditEvent');
    const btnDelete = document.getElementById('btnDeleteEvent');
    
    if (btnEdit) {
        btnEdit.addEventListener('click', async function() {
            if (!window.selectedEvent) {
                alert('Select a schedule first');
                return;
            }
            
            const id = window.selectedEvent.id;
            try {
                const response = await fetch(`get_event.php?id=${encodeURIComponent(id)}`);
                const eventData = await response.json();
                
                if (eventData) {
                    // Populate edit form
                    const editForm = document.getElementById('editEventForm');
                    if (editForm) {
                        document.getElementById('editEventId').value = eventData.id;
                        document.getElementById('editEventTitle').value = eventData.title;
                        document.getElementById('editEventDescription').value = eventData.description || '';
                        document.getElementById('editEventLocation').value = eventData.location || '';
                        document.getElementById('editEventStart').value = eventData.start_date;
                        document.getElementById('editEventEnd').value = eventData.end_date || '';
                        document.getElementById('editEventAllDay').checked = eventData.all_day === '1';
                        
                        // Show edit modal
                        const editModal = new bootstrap.Modal(document.getElementById('editEventModal'));
                        editModal.show();
                    }
                }
            } catch (error) {
                console.error('Error fetching event details:', error);
                alert('Failed to load event details');
            }
        });
    }
    
    if (btnDelete) {
        btnDelete.addEventListener('click', async function() {
            if (!window.selectedEvent || !confirm('Are you sure you want to delete this event?')) {
                return;
            }
            
            try {
                const response = await fetch('delete_event.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `id=${encodeURIComponent(window.selectedEvent.id)}`
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Refresh calendar
                    calendar.refetchEvents();
                    // Close any open modals
                    const modals = document.querySelectorAll('.modal');
                    modals.forEach(modal => {
                        const bsModal = bootstrap.Modal.getInstance(modal);
                        if (bsModal) bsModal.hide();
                    });
                    // Clear selection
                    window.selectedEvent = null;
                    // Show success message
                    alert('Event deleted successfully!');
                } else {
                    throw new Error(result.message || 'Failed to delete event');
                }
            } catch (error) {
                console.error('Error deleting event:', error);
                alert('Error: ' + (error.message || 'Failed to delete event'));
            }
        });
    }
    
    // Handle edit form submission
    const editEventForm = document.getElementById('editEventForm');
    if (editEventForm) {
        editEventForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(editEventForm);
            const submitBtn = editEventForm.querySelector('[type="submit"]');
            const originalBtnText = submitBtn.innerHTML;
            
            try {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...';
                
                const response = await fetch('update_event.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Refresh calendar
                    calendar.refetchEvents();
                    // Close modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('editEventModal'));
                    if (modal) modal.hide();
                    // Clear selection
                    window.selectedEvent = null;
                    // Show success message
                    alert('Event updated successfully!');
                } else {
                    throw new Error(result.message || 'Failed to update event');
                }
            } catch (error) {
                console.error('Error updating event:', error);
                alert('Error: ' + (error.message || 'Failed to update event'));
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnText;
            }
        });
    }
});
