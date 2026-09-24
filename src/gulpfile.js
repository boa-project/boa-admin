var fs = require('fs');
var gulp = require('gulp');
var uglify = require('gulp-uglify');
var cleanCSS = require('gulp-clean-css');
var rename = require('gulp-rename');
var concat = require('gulp-concat');
var pump = require('pump');

var RESDIR = 'boa/plugins/gui.ajax/res/';
var JSDIR = 'boa/plugins/gui.ajax/res/js/';
var THEMESDIR = 'boa/plugins/gui.ajax/res/themes/umbra/css/';

var getFilePaths = function (filename) {
  var contents = fs.readFileSync(__dirname + '/' + filename, 'utf8');
  var toResDir = function (it) { return RESDIR + it; };
  var r = contents.split(/\r?\n/);
  return r.map(toResDir);
};

var uglifyOptions = {
  compress: { 
    hoist_funs: false 
  }, 
  mangle: { 
    reserved: ['$super']
  }
};

gulp.task('app_boot', function(cb){
  return pump([
    gulp.src(getFilePaths(JSDIR+'app_boot_list.txt')),
    concat('app_boot.js'),
    uglify(uglifyOptions),
    gulp.dest(JSDIR)
  ]);
});

gulp.task('app_boot_protolegacy', function(){
  return pump([
    gulp.src(getFilePaths(JSDIR+'app_boot_protolegacy_list.txt')),
    concat('app_boot_protolegacy.js'),
    uglify(),
    gulp.dest(JSDIR)
  ]);
});

gulp.task('app', function(){
  return pump([
    gulp.src(getFilePaths(JSDIR+'app_list.txt')),
    concat('app.js'),
    uglify(uglifyOptions),
    gulp.dest(JSDIR)
  ]);
});

gulp.task('themes-css', function(){
  return pump([
    gulp.src(getFilePaths(THEMESDIR+'allz_list.txt')),
    concat('allz.css'),
    cleanCSS({ level: { 1: { specialComments: 0 } } }),
    gulp.dest(THEMESDIR)
  ]);
});

gulp.task('build', gulp.series('app_boot', 'app_boot_protolegacy', 'app', 'themes-css'));

gulp.task('default', gulp.series('build'));
