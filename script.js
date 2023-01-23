var changeColor = document.querySelector(".items_color input");
var items = document.querySelector(".items");
changeColor.addEventListener(
  "click",
  function () {
    items.classList.toggle("active");
  },
  false
);

items.addEventListener(
  "click",
  function () {
    openWindow = document.querySelectorAll(".item");
  },
  false
);

var windowBox = document.querySelector(".window");
var windowBack = document.querySelector(".window_back");
var windowClose = document.querySelector(".window_close");
var windowContent = document.querySelector(".window_content");
var openWindow = document.querySelectorAll(".item");
Array.prototype.forEach.call(openWindow, function (el) {
  el.onclick = function () {
    windowContent.innerHTML = el.innerHTML;
    console.log(windowBox);
    windowBack.classList.add("active");
    windowBox.classList.add("active");
  };
});
windowBack.addEventListener("click", CloseWindow);
windowClose.addEventListener("click", CloseWindow);
function CloseWindow() {
  windowBack.classList.remove("active");
  windowBox.classList.remove("active");
}

var itemAdd = document.querySelector(".item_add");
itemAdd.addEventListener(
  "click",
  function () {
    var itemIndex = document.querySelectorAll(".item").length;
    var newItem = document.createElement("div");
    newItem.classList.add("item");
    newItem.innerHTML = itemIndex + 1;
    items.insertBefore(newItem, itemAdd);
    newItem.onclick = function () {
      windowContent.innerHTML = newItem.innerHTML;
      console.log(windowBox);
      windowBack.classList.add("active");
      windowBox.classList.add("active");
    };
  },
  false
);
