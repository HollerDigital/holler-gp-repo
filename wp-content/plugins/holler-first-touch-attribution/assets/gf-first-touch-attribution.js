(function () {
  "use strict";

  var STORAGE_KEY = "gffta_first_touch_v1";

  function getConfig() {
    return (typeof window.GFFTA_CONFIG === "object" && window.GFFTA_CONFIG) ? window.GFFTA_CONFIG : { cookieDays: 90 };
  }

  function safeJsonParse(str) {
    try { return JSON.parse(str); } catch (e) { return {}; }
  }

  function readLS() {
    return safeJsonParse(window.localStorage.getItem(STORAGE_KEY) || "{}");
  }

  function writeLS(obj) {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(obj || {}));
  }

  function setCookie(name, value, days) {
    if (value === undefined || value === null) return;
    var d = new Date();
    d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
    document.cookie = name + "=" + encodeURIComponent(String(value)) +
      "; expires=" + d.toUTCString() + "; path=/; SameSite=Lax";
  }

  function getCookie(name) {
    var m = document.cookie.match(new RegExp("(^| )" + name + "=([^;]+)"));
    return m ? decodeURIComponent(m[2]) : "";
  }

  function getQueryParam(name) {
    try {
      var url = new URL(window.location.href);
      return url.searchParams.get(name) || "";
    } catch (e) {
      return "";
    }
  }

  function captureFirstTouch() {
    var cfg = getConfig();
    var days = cfg.cookieDays || 90;

    var data = readLS();

    // First landing page (only set once)
    if (!data.landing_page_first) {
      data.landing_page_first = window.location.pathname + window.location.search;
    }

    // First referrer (only set once)
    if (!data.referrer_first) {
      data.referrer_first = document.referrer || "";
    }

    // UTMs / click IDs (first touch only)
    var keys = ["utm_source","utm_medium","utm_campaign","utm_term","utm_content","gclid","msclkid","fbclid"];
    for (var i = 0; i < keys.length; i++) {
      var k = keys[i];
      var v = getQueryParam(k);
      if (v && !data[k]) data[k] = v;
    }

    // Infer channel + source from first-touch data
    var inferred = inferChannelSource(data);
    if (!data.channel) data.channel = inferred.channel;
    if (!data.source) data.source = inferred.source;

    // Persist
    writeLS(data);

    // Mirror the key fields to cookies (useful if you later want server-side population)
    setCookie("gffta_landing_page_first", data.landing_page_first, days);
    setCookie("gffta_referrer_first", data.referrer_first, days);
    setCookie("gffta_channel", data.channel, days);
    setCookie("gffta_source", data.source, days);

    // Optional mirrors
    for (var j = 0; j < keys.length; j++) {
      var kk = keys[j];
      if (data[kk]) setCookie("gffta_" + kk, data[kk], days);
    }
  }

  function inferChannelSource(data) {
    // Customize taxonomy here if needed.
    // Priority: click IDs -> UTM medium/source -> referrer -> direct
    if (data.gclid) return { channel: "Paid search", source: "Google" };
    if (data.msclkid) return { channel: "Paid search", source: "Bing" };

    var src = (data.utm_source || "").toLowerCase();
    var med = (data.utm_medium || "").toLowerCase();
    var ref = (data.referrer_first || "").toLowerCase();

    if (/(cpc|ppc|paid)/.test(med)) return { channel: "Paid", source: data.utm_source || "Unknown" };
    if (/(social)/.test(med) || /(facebook|instagram|linkedin|tiktok|x|twitter)/.test(src)) {
      return { channel: "Organic social", source: data.utm_source || "Social" };
    }
    if (ref.indexOf("google.") !== -1) return { channel: "Organic search", source: "Google" };
    if (ref.indexOf("bing.") !== -1) return { channel: "Organic search", source: "Bing" };
    if (data.referrer_first) return { channel: "Referral", source: data.referrer_first };
    return { channel: "Direct", source: "None" };
  }

  function populateGravityForms() {
    var data = readLS();

    // If localStorage is blocked, fall back to cookies
    if (!data || typeof data !== "object") data = {};
    if (!data.landing_page_first) data.landing_page_first = getCookie("gffta_landing_page_first") || "";
    if (!data.channel) data.channel = getCookie("gffta_channel") || "";
    if (!data.source) data.source = getCookie("gffta_source") || "";

    // Target inputs via CSS class on the FIELD (recommended)
    // Example field CSS class: gf-pop-channel
    function setByFieldClass(param, value) {
      var wrapper = document.querySelector(".gf-pop-" + param);
      if (!wrapper) return;
      var input = wrapper.querySelector("input, textarea, select");
      if (input) input.value = value || "";
    }

    // Your first-touch keys
    setByFieldClass("landing_page_first", data.landing_page_first || "");
    setByFieldClass("channel", data.channel || "");
    setByFieldClass("source", data.source || "");
  }

  // Run capture early
  try { captureFirstTouch(); } catch (e) {}

  // Populate on GF render (covers AJAX)
  document.addEventListener("gform_post_render", function () {
    try { populateGravityForms(); } catch (e) {}
  });

  // Also populate on normal loads
  document.addEventListener("DOMContentLoaded", function () {
    try { populateGravityForms(); } catch (e) {}
  });

})();