</main>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const toggleBtn = document.getElementById("sidebarToggle");
    const brandLeft = document.querySelector(".brand-left");

    // Cho phép kích hoạt animation transition sau khi trang đã render ổn định
    setTimeout(() => {
        document.body.classList.add("ready");
    }, 100);

    function toggleSidebar() {
        const isCollapsed = document.documentElement.classList.toggle("sidebar-collapsed");
        localStorage.setItem("sidebar_collapsed", isCollapsed);
    }

    if (toggleBtn) {
        toggleBtn.addEventListener("click", toggleSidebar);
    }

    if (brandLeft) {
        brandLeft.addEventListener("click", function () {
            if (document.documentElement.classList.contains("sidebar-collapsed")) {
                toggleSidebar();
            }
        });
    }
});
</script>
</body>
</html>