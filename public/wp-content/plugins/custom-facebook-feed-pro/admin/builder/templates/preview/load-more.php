<div v-if="sourcesList.length">
    <div id="cff-load-more-section" class="cff-preview-loadmore-ctn cff-fb-fs cff-preview-section" :data-dimmed="!isSectionHighLighted('loadMore')" v-if="valueIsEnabled(customizerFeedData.settings.loadmore) && customizerFeedData.settings.feedlayout != 'carousel' && customizerFeedData.settings.feedtype != 'singlealbum' && customizerFeedData.settings.feedtype != 'featuredpost'">
        <div class="cff-preview-loadmore-btn cff-fb-fs">{{customizerFeedData.settings.buttontext}}</div>
    </div>
</div>