<?php

// includes/footer.php

?>

    </main>



    <footer class="footer">

        <div class="container-fluid">

            &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars(SITE_NAME); ?>. All rights reserved.

        </div>

    </footer>



    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>



    <script>

        document.addEventListener('DOMContentLoaded', function() {

            // Function to auto hide alerts

            const autoHideAlerts = () => {

                const alerts = document.querySelectorAll('.alert');

                alerts.forEach(alert => {

                    if (!alert.dataset.manualClose) { // Check if it's not meant for manual close

                        setTimeout(() => {

                            alert.style.opacity = '0';

                            setTimeout(() => {

                                alert.remove();

                            }, 300); // Wait for fade out

                        }, 5000); // 5 seconds

                    }

                });

            };

            autoHideAlerts();



            // Add focus effects to form controls for glassmorphism feel

            document.querySelectorAll('.form-control').forEach(function(input) {

                input.addEventListener('focus', function() {

                    this.parentElement.style.transform = 'scale(1.01)';

                    this.parentElement.style.transition = 'transform 0.2s ease';

                });



                input.addEventListener('blur', function() {

                    this.parentElement.style.transform = 'scale(1)';

                });

            });



            // Smooth scroll for anchor links (if any)

            document.querySelectorAll('a[href^="#"]').forEach(anchor => {

                anchor.addEventListener('click', function (e) {

                    e.preventDefault();

                    document.querySelector(this.getAttribute('href')).scrollIntoView({

                        behavior: 'smooth'

                    });

                });

            });

        });

    </script>

</body>

</html>