var ready = (callback) => {
  if (document.readyState != "loading") callback();
  else document.addEventListener("DOMContentLoaded", callback);
}

ready(() => {
  /* Tabs */
  const tabBtns = document.querySelectorAll('.tabbtns');
  const tabSections = document.querySelectorAll('.maintabs');
  tabBtns.forEach(function (tab) {
    tab.addEventListener('click', function (evt) {
      const btnClicked = evt.target;
      const targetBtn = btnClicked.dataset.tab;
      console.log('target is ' + targetBtn)
      const targetShow = document.querySelector('#' + targetBtn);
      tabBtns.forEach((tab) => tab.classList.remove('tab-active'));
      tabSections.forEach((section) => section.classList.add('hidden'));
      btnClicked.classList.add('tab-active')
      if (targetShow) { targetShow.classList.remove('hidden') }

    });

  });






  
})

