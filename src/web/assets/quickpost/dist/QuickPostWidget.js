!function(){var t;t=jQuery,Craft.QuickPostWidget=Garnish.Base.extend({params:null,initFields:null,formHtml:null,$widget:null,$form:null,$saveBtn:null,$errorList:null,loading:!1,init:function(i,r,e,n){this.params=r,this.initFields=e,this.formHtml=n,this.$widget=t("#widget"+i),this.initForm(this.$widget.find("form:first"))},initForm:function(t){this.$form=t,this.$saveBtn=this.$form.find("button[type=submit]"),this.initFields();var i=this.$form.find("> .buttons > .btngroup > .menubtn"),r=i.data("menubtn").menu.$container.find("> ul > li > a");i.menubtn(),this.addListener(this.$form,"submit","handleFormSubmit"),this.addListener(r,"click","saveAndContinueEditing")},handleFormSubmit:function(t){t.preventDefault(),this.save(this.onSave.bind(this))},saveAndContinueEditing:function(){this.save(this.gotoEntry.bind(this))},save:function(i){var r=this;if(!this.loading){this.loading=!0,this.$saveBtn.addClass("loading");var e=Garnish.getPostData(this.$form),n=t.extend({enabled:1},e,this.params);Craft.sendActionRequest("POST","entries/save-entry",{data:n}).then((function(t){if(r.loading=!1,r.$saveBtn.removeClass("loading"),r.$errorList&&r.$errorList.children().remove(),!t.data.success)return Promise.reject();Craft.cp.displayNotice(Craft.t("app","Entry saved.")),i(t.data)})).catch((function(i){var e=i.response;if(r.loading=!1,r.$saveBtn.removeClass("loading"),r.$errorList&&r.$errorList.children().remove(),Craft.cp.displayError(Craft.t("app","Couldn’t save entry.")),e.data.errors)for(var n in r.$errorList||(r.$errorList=t('<ul class="errors"/>').insertAfter(r.$form)),e.data.errors)if(e.data.errors.hasOwnProperty(n))for(var a=0;a<e.data.errors[n].length;a++){var s=e.data.errors[n][a];t("<li>"+s+"</li>").appendTo(r.$errorList)}}))}},onSave:function(i){var r=t(this.formHtml);if(this.$form.replaceWith(r),Craft.initUiElements(r),this.initForm(r),void 0!==Craft.RecentEntriesWidget)for(var e=0;e<Craft.RecentEntriesWidget.instances.length;e++){var n=Craft.RecentEntriesWidget.instances[e];n.params.sectionId&&n.params.sectionId!=this.params.sectionId||n.addEntry({url:i.cpEditUrl,title:i.title,dateCreated:i.dateCreated,username:i.authorUsername})}},gotoEntry:function(t){Craft.redirectTo(t.cpEditUrl)}})}();
//# sourceMappingURL=QuickPostWidget.js.map