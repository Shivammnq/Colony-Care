<footer class="footer">
  <div class="container footer-grid">

    <!-- Logo + About -->
    <div class="footer-col">
      <h2 class="logos">🏢 ColonyCare</h2>
      <p>
        India's smartest residential community management platform for RWAs,
        housing societies, and gated colonies.
      </p>
    </div>

    <!-- Product -->
    <div class="footer-col">
      <h3>Product</h3>
      <ul>
        <li><a href="#features">Features</a></li>
        <li><a href="#pricing">Pricing</a></li>
        <li><a href="#roles">User Roles</a></li>
        <li><a href="#faq">FAQ</a></li>
      </ul>
    </div>

    <!-- Company -->
    <div class="footer-col">
      <h3>Company</h3>
      <ul>
        <li><a href="#">About Us</a></li>
        <li><a href="#">Blog</a></li>
        <li><a href="#">Careers</a></li>
        <li><a href="#">Privacy Policy</a></li>
      </ul>
    </div>

    <!-- Contact -->
    <div class="footer-col">
      <h3>Contact</h3>
      <ul class="contact">
        <li><a href="mailto:hello@colonycare.in">📧 hello@colonycare.in</a></li>
        <li><a href="tel:+919876543210">📞 +91 9XXXXXXXX</a></li>
        <li><a href="#">📍 New Delhi, India</a></li>
      </ul>
    </div>

  </div>

  <div class="footer-bottom">
    <p>© 2026 ColonyCare.in - All rights reserved.</p>
  </div>
</footer>

<script>
document.addEventListener("DOMContentLoaded", function () {

  const menuDots = document.getElementById("menuDots");
  const dropdown = document.getElementById("dropdownMenu");

  menuDots.addEventListener("click", function (e) {
    e.stopPropagation();
    dropdown.classList.toggle("active");
  });

  document.addEventListener("click", function () {
    dropdown.classList.remove("active");
  });

});

</script>

<script>
document.addEventListener("DOMContentLoaded", function () {

    const toggle = document.getElementById("togglePassword");
    const password = document.getElementById("password");

    toggle.addEventListener("click", function () {

        // Toggle type
        const type = password.getAttribute("type") === "password" ? "text" : "password";
        password.setAttribute("type", type);

        // Toggle icon
        this.classList.toggle("fa-eye");
        this.classList.toggle("fa-eye-slash");
    });

});
</script>

<script>
const track = document.querySelector('.testimonial-track');
const slides = document.querySelectorAll('.testimonial-slide');
const next = document.querySelector('.next');
const prev = document.querySelector('.prev');
const dots = document.querySelectorAll('.dot');
const slider = document.querySelector('.testimonial-slider');

let index = 0;
let interval;

/* UPDATE */
function updateSlider() {
    track.style.transform = `translateX(-${index * 100}%)`;

    dots.forEach(dot => dot.classList.remove('active'));
    dots[index].classList.add('active');
}

/* NEXT */
next.addEventListener('click', () => {
    index = (index + 1) % slides.length;
    updateSlider();
});

/* PREV */
prev.addEventListener('click', () => {
    index = (index - 1 + slides.length) % slides.length;
    updateSlider();
});

/* DOT CLICK */
dots.forEach((dot, i) => {
    dot.addEventListener('click', () => {
        index = i;
        updateSlider();
    });
});

/* AUTO SLIDE FUNCTION */
function startAutoSlide() {
    interval = setInterval(() => {
        index = (index + 1) % slides.length;
        updateSlider();
    }, 4000);
}

/* STOP ON HOVER */
slider.addEventListener('mouseenter', () => {
    clearInterval(interval);
});

/* RESUME */
slider.addEventListener('mouseleave', () => {
    startAutoSlide();
});

/* INIT */
startAutoSlide();
</script>

</body>
</html>