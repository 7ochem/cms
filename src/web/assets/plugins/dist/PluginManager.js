!function(){var e={811:function(){},447:function(e,t,n){var i=n(811);i.__esModule&&(i=i.default),"string"==typeof i&&(i=[[e.id,i,""]]),i.locals&&(e.exports=i.locals),(0,n(673).Z)("7690077b",i,!0,{})},673:function(e,t,n){"use strict";function i(e,t){for(var n=[],i={},a=0;a<t.length;a++){var s=t[a],r=s[0],l={id:e+":"+a,css:s[1],media:s[2],sourceMap:s[3]};i[r]?i[r].parts.push(l):n.push(i[r]={id:r,parts:[l]})}return n}n.d(t,{Z:function(){return f}});var a="undefined"!=typeof document;if("undefined"!=typeof DEBUG&&DEBUG&&!a)throw new Error("vue-style-loader cannot be used in a non-browser environment. Use { target: 'node' } in your Webpack config to indicate a server-rendering environment.");var s={},r=a&&(document.head||document.getElementsByTagName("head")[0]),l=null,d=0,o=!1,p=function(){},u=null,c="data-vue-ssr-id",h="undefined"!=typeof navigator&&/msie [6-9]\b/.test(navigator.userAgent.toLowerCase());function f(e,t,n,a){o=n,u=a||{};var r=i(e,t);return g(r),function(t){for(var n=[],a=0;a<r.length;a++){var l=r[a];(d=s[l.id]).refs--,n.push(d)}for(t?g(r=i(e,t)):r=[],a=0;a<n.length;a++){var d;if(0===(d=n[a]).refs){for(var o=0;o<d.parts.length;o++)d.parts[o]();delete s[d.id]}}}}function g(e){for(var t=0;t<e.length;t++){var n=e[t],i=s[n.id];if(i){i.refs++;for(var a=0;a<i.parts.length;a++)i.parts[a](n.parts[a]);for(;a<n.parts.length;a++)i.parts.push(m(n.parts[a]));i.parts.length>n.parts.length&&(i.parts.length=n.parts.length)}else{var r=[];for(a=0;a<n.parts.length;a++)r.push(m(n.parts[a]));s[n.id]={id:n.id,refs:1,parts:r}}}}function v(){var e=document.createElement("style");return e.type="text/css",r.appendChild(e),e}function m(e){var t,n,i=document.querySelector("style["+c+'~="'+e.id+'"]');if(i){if(o)return p;i.parentNode.removeChild(i)}if(h){var a=d++;i=l||(l=v()),t=b.bind(null,i,a,!1),n=b.bind(null,i,a,!0)}else i=v(),t=$.bind(null,i),n=function(){i.parentNode.removeChild(i)};return t(e),function(i){if(i){if(i.css===e.css&&i.media===e.media&&i.sourceMap===e.sourceMap)return;t(e=i)}else n()}}var y,C=(y=[],function(e,t){return y[e]=t,y.filter(Boolean).join("\n")});function b(e,t,n,i){var a=n?"":i.css;if(e.styleSheet)e.styleSheet.cssText=C(t,a);else{var s=document.createTextNode(a),r=e.childNodes;r[t]&&e.removeChild(r[t]),r.length?e.insertBefore(s,r[t]):e.appendChild(s)}}function $(e,t){var n=t.css,i=t.media,a=t.sourceMap;if(i&&e.setAttribute("media",i),u.ssrId&&e.setAttribute(c,t.id),a&&(n+="\n/*# sourceURL="+a.sources[0]+" */",n+="\n/*# sourceMappingURL=data:application/json;base64,"+btoa(unescape(encodeURIComponent(JSON.stringify(a))))+" */"),e.styleSheet)e.styleSheet.cssText=n;else{for(;e.firstChild;)e.removeChild(e.firstChild);e.appendChild(document.createTextNode(n))}}}},t={};function n(i){var a=t[i];if(void 0!==a)return a.exports;var s=t[i]={id:i,exports:{}};return e[i](s,s.exports,n),s.exports}n.n=function(e){var t=e&&e.__esModule?function(){return e.default}:function(){return e};return n.d(t,{a:t}),t},n.d=function(e,t){for(var i in t)n.o(t,i)&&!n.o(e,i)&&Object.defineProperty(e,i,{enumerable:!0,get:t[i]})},n.o=function(e,t){return Object.prototype.hasOwnProperty.call(e,t)},function(){"use strict";n(447),function(e){Craft.PluginManager=Garnish.Base.extend({init:function(){var n=this;this.getPluginLicenseInfo().then((function(i){for(var a in i)i.hasOwnProperty(a)&&(i[a].isComposerInstalled?new t(n,e("#plugin-"+a)).update(i[a]):n.addUninstalledPluginRow(a,i[a]))}))},getPluginLicenseInfo:function(){return new Promise((function(e,t){Craft.sendApiRequest("GET","cms-licenses",{params:{include:"plugins"}}).then((function(n){var i={pluginLicenses:n.license.pluginLicenses||[]};Craft.sendActionRequest("POST","app/get-plugin-license-info",{data:i}).then((function(t){e(t.data)})).catch((function(){t()}))})).catch(t)}))},addUninstalledPluginRow:function(t,n){var i=e("#plugins");i.length||(e("<table/>",{id:"plugins",class:"data fullwidth collapsible",html:"<tbody></tbody>"}),function(e){throw new TypeError('"$table" is read-only')}(),e("#no-plugins").replaceWith(i));var a=e("<tr/>",{data:{handle:t}}).appendTo(i.children("tbody")).append(e("<th/>").append(e("<div/>",{class:"plugin-infos"}).append(e("<div/>",{class:"icon"}).append(e("<img/>",{src:n.iconUrl}))).append(e("<div/>",{class:"plugin-details"}).append(e("<h2/>",{text:n.name})).append(n.description?e("<p/>",{text:n.description}):e()).append(n.documentationUrl?e("<p/>",{class:"links"}).append(e("<a/>",{href:n.documentationUrl,target:"_blank",text:Craft.t("app","Documentation")})):e()).append(e("<div/>",{class:"flex license-key"}).append(e("<div />",{class:"pane"}).append(e("<input/>",{class:"text code",size:29,maxlength:29,value:Craft.PluginManager.normalizeUserKey(n.licenseKey),readonly:!0,disabled:!0}))))))).append(e("<td/>",{class:"nowrap","data-title":Craft.t("app","Status")}).append(e("<span/>",{class:"status"})).append(e("<span/>",{class:"light",text:Craft.t("app","Missing")}))).append(n.latestVersion?e("<td/>",{class:"nowrap thin","data-title":Craft.t("app","Action")}).append(e("<form/>",{method:"post","accept-charset":"UTF-8"}).append(e("<input/>",{type:"hidden",name:"action",value:"pluginstore/install"})).append(e("<input/>",{type:"hidden",name:"packageName",value:n.packageName})).append(e("<input/>",{type:"hidden",name:"handle",value:t})).append(e("<input/>",{type:"hidden",name:"edition",value:n.licensedEdition})).append(e("<input/>",{type:"hidden",name:"version",value:n.latestVersion})).append(e("<input/>",{type:"hidden",name:"licenseKey",value:n.licenseKey})).append(e("<input/>",{type:"hidden",name:"return",value:"settings/plugins"})).append(Craft.getCsrfInput()).append(e("<div/>",{class:"btngroup"}).append(e("<button/>",{type:"button",class:"btn menubtn","data-icon":"settings"})).append(e("<div/>",{class:"menu","data-align":"right"}).append(e("<ul/>").append(e("<li/>").append(e("<a/>",{class:"formsubmit",text:Craft.t("app","Install")}))))))):e());Craft.initUiElements(a)}},{normalizeUserKey:function(e){return"string"!=typeof e||""===e?"":"$"===e[0]?e:e.replace(/.{4}/g,"$&-").substring(0,29).toUpperCase()}});var t=Garnish.Base.extend({manager:null,$row:null,$details:null,$keyContainer:null,$keyInput:null,$spinner:null,$buyBtn:null,handle:null,updateTimeout:null,init:function(e,t){this.manager=e,this.$row=t,this.$details=this.$row.find(".plugin-details"),this.$keyContainer=t.find(".license-key"),this.$keyInput=this.$keyContainer.find("input.text").removeAttr("readonly"),this.$buyBtn=this.$keyContainer.find(".btn"),this.$spinner=t.find(".spinner"),this.handle=this.$row.data("handle"),this.addListener(this.$keyInput,"focus","onKeyFocus"),this.addListener(this.$keyInput,"input","onKeyChange")},getKey:function(){return this.$keyInput.val().replace(/\-/g,"").toUpperCase()},onKeyFocus:function(){this.$keyInput.select()},onKeyChange:function(){this.updateTimeout&&clearTimeout(this.updateTimeout);var e=this.getKey();if(0===e.length||24===e.length||e.length>1&&"$"===e[0]){var t=Craft.PluginManager.normalizeUserKey(e);this.$keyInput.val(t),this.updateTimeout=setTimeout(this.updateLicenseStatus.bind(this),100)}},updateLicenseStatus:function(){var e=this;this.$spinner.removeClass("hidden");var t={handle:this.handle,key:this.getKey()};Craft.sendActionRequest("POST","app/update-plugin-license",{data:t}).then((function(){e.manager.getPluginLicenseInfo().then((function(t){e.$spinner.addClass("hidden"),e.update(t[e.handle])}))}))},update:function(t){var n=this.$row.find(".license-key-status");if("valid"==t.licenseKeyStatus||t.licenseIssues.length){var i=e("<span/>",{class:"license-key-status "+(0===t.licenseIssues.length?"valid":"")});n.length?n.replaceWith(i):i.appendTo(this.$row.find(".icon"))}else n.length&&n.remove();var a=this.$row.find(".edition");if(t.hasMultipleEditions||t.isTrial){var s=t.upgradeAvailable?e("<a/>",{href:Craft.getUrl("plugin-store/"+this.handle),class:"edition"}):e("<div/>",{class:"edition"});t.hasMultipleEditions&&e("<div/>",{class:"edition-name",text:t.edition}).appendTo(s),t.isTrial&&e("<div/>",{class:"edition-trial",text:Craft.t("app","Trial")}).appendTo(s),a.length?a.replaceWith(s):s.insertBefore(this.$row.find(".version"))}else a.length&&a.remove();var r=t.licenseKey||"unknown"!==t.licenseKeyStatus;if(r?(this.$keyContainer.removeClass("hidden"),t.licenseKey&&!this.$keyInput.val().match(/^\$/)&&this.$keyInput.val(Craft.PluginManager.normalizeUserKey(t.licenseKey))):this.$keyContainer.addClass("hidden"),r&&t.licenseIssues.length?this.$keyInput.addClass("error"):this.$keyInput.removeClass("error"),this.$row.find("p.error").remove(),t.licenseIssues.length){for(var l=e(),d=0;d<t.licenseIssues.length;d++){var o=void 0;switch(t.licenseIssues[d]){case"no_trials":o=Craft.t("app","Plugin trials are not allowed on this domain.");break;case"wrong_edition":o=Craft.t("app","This license is for the {name} edition.",{name:t.licensedEdition.charAt(0).toUpperCase()+t.licensedEdition.substring(1)})+' <button type="button" class="btn submit small formsubmit">'+Craft.t("app","Switch")+"</button>";break;case"mismatched":o=Craft.t("app",'This license is tied to another Craft install. Visit {accountLink} to detach it, or <a href="{buyUrl}">buy a new license</a>',{accountLink:'<a href="https://id.craftcms.com" rel="noopener" target="_blank">id.craftcms.com</a>',buyUrl:Craft.getCpUrl("plugin-store/buy/".concat(this.handle,"/").concat(t.edition))});break;case"astray":o=Craft.t("app","This license isn’t allowed to run version {version}.",{version:t.version});break;case"required":o=Craft.t("app","A license key is required.");break;default:o=Craft.t("app","Your license key is invalid.")}var p=e("<p/>",{class:"error",html:o});if("wrong_edition"===t.licenseIssues[d]){var u=e("<form/>",{method:"post","accept-charset":"UTF-8"}).append(Craft.getCsrfInput()).append(e("<input/>",{type:"hidden",name:"action",value:"plugins/switch-edition"})).append(e("<input/>",{type:"hidden",name:"pluginHandle",value:this.handle})).append(e("<input/>",{type:"hidden",name:"edition",value:t.licensedEdition})).append(p);Craft.initUiElements(u),l=l.add(u)}else l=l.add(p)}l.appendTo(this.$details),Craft.initUiElements()}var c=this.$row.find(".expired");if(t.expired){var h=e("<p/>",{class:"warning with-icon expired",html:Craft.t("app","This license has expired.")+" "+Craft.t("app","<a>Renew now</a> for another year of updates.").replace("<a>",'<a href="'+t.renewalUrl+'" target="_blank">')});c.length?c.replaceWith(h):h.appendTo(this.$details)}"trial"===t.licenseKeyStatus?(this.$buyBtn.removeClass("hidden"),t.licenseIssues.length?this.$buyBtn.addClass("submit"):this.$buyBtn.removeClass("submit")):this.$buyBtn.addClass("hidden")}})}(jQuery)}()}();
//# sourceMappingURL=PluginManager.js.map