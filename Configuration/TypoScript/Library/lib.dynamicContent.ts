################################################
#### DYNAMIC CONTENT LIB FOR USAGE IN FLUID ####
################################################
# Taken from bootstrap package https://github.com/benjaminkott/bootstrap_package
#
#  EXAMPLE
#  ---------------
#  <f:cObject typoscriptObjectPath="lib.dynamicContent" data="{
#      pageUid: '{data.uid}',
#      colPos: '0',
#      slide: '0',
#      wrap: '<div class=\"hero\">|</div>',
#      elementWrap: '<div class=\"element\">|</div>'
#  }" />
#
#
#  COLUMN NUMBERS
#  ---------------
#
#  0  = main
#  1  = left
#  2  = right
#  3  = border
#  4  = main2
#
#  8  = content before
#  9  = content after
#
#  10 = footer1
#  11 = footer2
#  12 = footer3
#
#  20 = middle1
#  21 = middle2
#  22 = middle3
#
#  30 = special1
#  31 = special2
#  32 = special3
#  33 = special4
#  34 = special5
#  35 = special6
#  36 = special7
#  37 = special8
#  38 = special9
#  39 = special10
#
#################
lib.dynamicContent = COA
lib.dynamicContent {
    5 = LOAD_REGISTER
    5 {
        colPos.cObject = TEXT
        colPos.cObject {
            field = colPos
            ifEmpty.cObject = TEXT
            ifEmpty.cObject {
                value.current = 1
                ifEmpty = 0
            }
        }
        slide.cObject = TEXT
        slide.cObject {
            override {
                field = slide
                if {
                    isInList.field = slide
                    value = -1, 0, 1, 2
                }
            }
            ifEmpty = 0
        }
        pageUid.cObject = TEXT
        pageUid.cObject {
            field = pageUid
            ifEmpty.data = TSFE:id
        }
        contentFromPid.cObject = TEXT
        contentFromPid.cObject {
            data = DB:pages:{register:pageUid}:content_from_pid
            data.insertData = 1
        }
        wrap.cObject = TEXT
        wrap.cObject {
            field = wrap
        }
        elementWrap.cObject = TEXT
        elementWrap.cObject{
            field = elementWrap
        }
    }
    20 = CONTENT
    20 {
        table = tt_content
        select {
            includeRecordsWithoutDefaultTranslation = 1
            orderBy = sorting
            where = {#colPos}={register:colPos}
            where.insertData = 1
            pidInList.data = register:pageUid
            pidInList.override.data = register:contentFromPid
        }
        slide = {register:slide}
        slide.insertData = 1
        renderObj {
            stdWrap{
                dataWrap = {register:elementWrap}
                required = 1
            }
        }
        stdWrap {
            dataWrap = {register:wrap}
            required = 1
        }
    }
    90 = RESTORE_REGISTER
}
lib.dynamicContentSlide =< lib.dynamicContent
lib.dynamicContentSlide.20.slide = -1
