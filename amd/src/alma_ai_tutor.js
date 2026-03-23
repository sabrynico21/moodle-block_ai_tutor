/**
 * @copyright 2025 Université TÉLUQ and the UNIVERSITÉ GASTON BERGER DE SAINT-LOUIS
 */

define(['jquery', 'core/str', 'core/ajax', 'core/notification'], function($, str, ajax, notification) {
    return {
        init: function(wwwroot, sesskey, userid, courseid, sectionid, instanceid, savedsectionid) {
            // Hiding the block if is not the same section where it was created
            const wrongSection = (savedsectionid > 0 && sectionid !== savedsectionid) ||
                                (savedsectionid === 0 && sectionid > 0);

            if (wrongSection) {
                const blockEl = document.querySelector('.block_alma_ai_tutor[data-instanceid="' + instanceid + '"]');
                if (blockEl) {
                    const blockContainer = blockEl.closest('.block');
                    if (blockContainer) blockContainer.style.display = 'none';
                }
                return;
            }
            
            // Unique selectors for instanceid
            const $chatform   = $('#chatform-'     + instanceid);
            const $question   = $('#question-'     + instanceid);
            const $messages   = $('#chat-messages-'+ instanceid);
            const $errorDiv   = $('#error-message-'+ instanceid);

            // Toggle prompt modal
            const togglePromptModal = function(iid) {
                const id = iid || instanceid;
                const modal = document.querySelector('#promptModal-' + id);
                if (modal) {
                    modal.style.display = modal.style.display === 'none' ? 'block' : 'none';
                }
            };

            // Toggle file upload modal
            const toggleFileUploadModal = function (iid) {
                const id = iid || instanceid;
                const modal = document.querySelector('#fileUploadModal-' + id);
                if (modal) {
                    modal.style.display = modal.style.display === 'none' || modal.style.display === '' ? 'flex' : 'none';

                    if (modal.style.display === 'none') {
                        const form = document.querySelector('#fileUploadForm-' + id);
                        const container = document.querySelector('#uploadedFilesContainer-' + id);
                        if (form) form.reset();
                        if (container) container.innerHTML = '';
                    }
                }
            };

            /**
             * Convert files to base64 for transmission
             * @param {FileList} files
             * @return {Promise<Array>}
             */
            const convertFilesToBase64 = (files) => {
                const filePromises = Array.from(files).map(file => {
                    return new Promise((resolve, reject) => {
                        const reader = new FileReader();
                        reader.onload = () => {
                            const base64Content = reader.result.split(',')[1]; // Remove data:... prefix
                            resolve({
                                filename: file.name,
                                filecontent: base64Content
                            });
                        };
                        reader.onerror = reject;
                        reader.readAsDataURL(file);
                    });
                });
                return Promise.all(filePromises);
            };

            // Handle chat form submission
            $chatform.on("submit", function(e) {
                e.preventDefault();
                const question = $question.val();

                str.get_string('sending_question', 'block_alma_ai_tutor').then(function(s) {
                    $errorDiv.text(s);
                }).catch(function() {
                    str.get_string('sending_question_fallback', 'block_alma_ai_tutor').then(function(s) {
                        $errorDiv.text(s);
                    }).catch(function() {
                        $errorDiv.text("Sending the question...");
                    });
                });

                // Use AJAX call instead of fetch
                const request = {
                    methodname: 'block_alma_ai_tutor_send_question',
                    args: {
                        question: question,
                        userid: userid,
                        courseid: courseid,
                        sectionid: sectionid,
                        instanceid: instanceid,
                        sansrag: false
                    }
                };

                ajax.call([request])[0]
                    .then(function(data) {
                        $errorDiv.text("");
                        if (data.status === 'error') {
                            str.get_string('error', 'block_alma_ai_tutor').then(function(s) {
                                $errorDiv.text(s + ': ' + data.error);
                            }).catch(function() {
                                $errorDiv.text("Error: " + data.error);
                            });
                            return;
                        }
                        $messages.append(
                            '<div class="message user-message">' + question + '</div>' +
                            '<div class="message bot-message">'  + data.answer  + '</div>'
                        );
                        $question.val("");
                        $messages.scrollTop($messages[0].scrollHeight);
                    })
                    .catch(function(error) {
                        console.error("Error:", error);
                        str.get_string('unknown_error_occurred', 'block_alma_ai_tutor').then(function(unknownErrorStr) {
                            const errorMessage = error && error.message ? error.message : unknownErrorStr;
                            str.get_string('error_sending_question', 'block_alma_ai_tutor').then(function(s) {
                                $errorDiv.text(s + errorMessage);
                            }).catch(function() {
                                $errorDiv.text("Error sending the question: " + errorMessage);
                            });
                        }).catch(function() {
                            const errorMessage = error && error.message ? error.message : 'Unknown error occurred';
                            $errorDiv.text("Error sending the question: " + errorMessage);
                        });
                    });
            });

            // Handle prompt form submission
            $('#promptform-' + instanceid).on("submit", function(e) {
                e.preventDefault();
                const prompttext = $('#prompttext-' + instanceid).val();

                str.get_string('saving_prompt', 'block_alma_ai_tutor').then(function(s) {
                    $errorDiv.text(s);
                }).catch(function() {
                    str.get_string('saving_prompt_fallback', 'block_alma_ai_tutor').then(function(s) {
                        $errorDiv.text(s);
                    }).catch(function() {
                        $errorDiv.text("Saving the prompt...");
                    });
                });

                // Use AJAX call instead of fetch
                const request = {
                    methodname: 'block_alma_ai_tutor_save_prompt',
                    args: {
                        prompttext: prompttext,
                        userid: userid,
                        courseid: courseid,
                        instanceid: instanceid
                    }
                };

                ajax.call([request])[0]
                    .then(function(data) {
                        $errorDiv.text("");
                        if (data.status === 'error') {
                            str.get_string('error', 'block_alma_ai_tutor').then(function(s) {
                                $errorDiv.text(s + ': ' + data.error);
                            }).catch(function() {
                                $errorDiv.text("Error: " + data.error);
                            });
                            return;
                        }
                        str.get_string('prompt_saved_successfully', 'block_alma_ai_tutor').then(function(s) {
                            $errorDiv.text(s);
                        }).catch(function() {
                            $errorDiv.text("Prompt saved successfully!");
                        });
                        togglePromptModal(instanceid);
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    })
                    .catch(function(error) {
                        console.error("Error:", error);
                        
                        str.get_string('unknown_error_occurred', 'block_alma_ai_tutor').then(function(unknownErrorStr) {
                            const errorMessage = error && error.message ? error.message : unknownErrorStr;
                            
                            str.get_string('error_saving_prompt', 'block_alma_ai_tutor').then(function(s) {
                                $errorDiv.text(s + errorMessage);
                            }).catch(function() {
                                $errorDiv.text("Error saving the prompt: " + errorMessage);
                            });
                        }).catch(function() {
                            const errorMessage = error && error.message ? error.message : 'Unknown error occurred';
                            $errorDiv.text("Error saving the prompt: " + errorMessage);
                        });
                    });
            });

            // Handle file upload form submission
            const fileUploadForm = document.querySelector('#fileUploadForm-' + instanceid);
            if (fileUploadForm) {
                fileUploadForm.addEventListener("submit", function(e) {
                    e.preventDefault();
                    
                    const fileInput = document.querySelector('#file-' + instanceid);

                    if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
                        str.get_string('no_files_selected', 'block_alma_ai_tutor').then(function(s) {
                            $errorDiv.text(s);
                        }).catch(function() {
                            $errorDiv.text("No files selected.");
                        });
                        return;
                    }

                    str.get_string('uploading_file', 'block_alma_ai_tutor').then(function(s) {
                        $errorDiv.text(s);
                    }).catch(function() {
                        $errorDiv.text("Uploading files...");
                    });

                    // Convert files to base64 and send via external service
                    convertFilesToBase64(fileInput.files)
                        .then(function(filesData) {
                            const request = {
                                methodname: 'block_alma_ai_tutor_upload_files',
                                args: {
                                    courseid: courseid,
                                    files: filesData
                                }
                            };
                            return ajax.call([request])[0];
                        })
                        .then(function(response) {
                            $errorDiv.text("");
                            
                            if (response && response.success) {
                                str.get_string('file_indexed_successfully', 'block_alma_ai_tutor').then(function(s) {
                                    $errorDiv.text(s);
                                }).catch(function() {
                                    $errorDiv.text(response.message || "Files indexed successfully!");
                                });

                                // Reset form and close modal
                                fileUploadForm.reset();
                                const container = document.querySelector('#uploadedFilesContainer-' + instanceid);
                                if (container) container.innerHTML = '';
                                toggleFileUploadModal(instanceid);

                                setTimeout(function() {
                                    location.reload();
                                }, 1500);
                            } else {
                                str.get_string('unknown_error', 'block_alma_ai_tutor').then(function(unknownErrorStr) {
                                    const responseMessage = response && response.message ? response.message : unknownErrorStr;
                                    
                                    str.get_string('error_processing_file', 'block_alma_ai_tutor').then(function(s) {
                                        $errorDiv.text(s + ': ' + responseMessage);
                                    }).catch(function() {
                                        $errorDiv.text("Error processing files: " + responseMessage);
                                    });
                                }).catch(function() {
                                    const responseMessage = response && response.message ? response.message : 'Unknown error';
                                    $errorDiv.text("Error processing files: " + responseMessage);
                                });
                            }
                        })
                        .catch(function(error) {
                            console.error("Upload error details:", error);
                            
                            // If the error contains a raw response, display it.
                            if (error && error.responseText) {
                                str.get_string('raw_server_response_debug', 'block_alma_ai_tutor').then(function(s) {
                                    $errorDiv.text(s);
                                }).catch(function() {
                                    $errorDiv.text("Server response error. Check console for details.");
                                });
                                return;
                            }
                            
                            // Secure error handling
                            str.get_string('unknown_error_occurred', 'block_alma_ai_tutor').then(function(unknownErrorStr) {
                                let errorMessage = unknownErrorStr;
                                if (error) {
                                    if (typeof error === 'string') {
                                        errorMessage = error;
                                    } else if (error.message) {
                                        errorMessage = error.message;
                                    } else if (error.error) {
                                        errorMessage = error.error;
                                    } else if (error.exception && error.exception.message) {
                                        errorMessage = error.exception.message;
                                    } else {
                                        errorMessage = "Server error - check console for details";
                                    }
                                }
                                
                                str.get_string('error_processing_file', 'block_alma_ai_tutor').then(function(s) {
                                    $errorDiv.text(s + ': ' + errorMessage);
                                }).catch(function() {
                                    $errorDiv.text("Error processing files: " + errorMessage);
                                });
                            }).catch(function() {
                                let errorMessage = 'Unknown error occurred';
                                if (error) {
                                    if (typeof error === 'string') {
                                        errorMessage = error;
                                    } else if (error.message) {
                                        errorMessage = error.message;
                                    } else if (error.error) {
                                        errorMessage = error.error;
                                    } else if (error.exception && error.exception.message) {
                                        errorMessage = error.exception.message;
                                    } else {
                                        errorMessage = "Server error - check console for details";
                                    }
                                }
                                $errorDiv.text("Error processing files: " + errorMessage);
                            });
                        });
                });
            }

            // Expose functions to the global scope for HTML onclick handlers
            window.togglePromptModal = togglePromptModal;
            window.toggleFileUploadModal = toggleFileUploadModal;
        }
    };
});